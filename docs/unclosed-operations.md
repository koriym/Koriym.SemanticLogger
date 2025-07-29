# Unclosed Operations - Troubleshooting Guide

## What is an Unclosed Operation?

An `UnclosedLogicException` occurs when you call `flush()` without properly closing all opened operations with `close()`. This is a **programming error** that should be fixed.

## Common Scenarios

### 1. Missing close() Call

**❌ Problematic Code:**
```php
$logger = new SemanticLogger();
$logger->open(new DatabaseTransactionContext());
$logger->event(new QueryExecutedContext($sql));
// Missing: $logger->close(...)
$logJson = $logger->flush(); // ❌ UnclosedLogicException
```

**✅ Fixed Code:**
```php
$logger = new SemanticLogger();
$logger->open(new DatabaseTransactionContext());
$logger->event(new QueryExecutedContext($sql));
$logger->close(new TransactionCompletedContext($result)); // ✅ Properly closed
$logJson = $logger->flush();
```

### 2. Exception Handling Without Close

**❌ Problematic Code:**
```php
$logger->open(new FileProcessingContext($filename));
try {
    $data = $this->processFile($filename);
    $logger->close(new ProcessingSuccessContext($data));
} catch (FileNotFoundException $e) {
    // Missing close for error case!
    throw $e;
}
$logJson = $logger->flush(); // ❌ UnclosedLogicException if exception occurred
```

**✅ Fixed Code:**
```php
$logger->open(new FileProcessingContext($filename));
try {
    $data = $this->processFile($filename);
    $logger->close(new ProcessingSuccessContext($data));
} catch (FileNotFoundException $e) {
    $logger->close(new ProcessingFailureContext($e->getMessage())); // ✅ Close on error
    throw $e;
} finally {
    // Alternative: Always close in finally block
}
$logJson = $logger->flush();
```

### 3. Nested Operations with Partial Closing

**❌ Problematic Code:**
```php
$logger->open(new ApiRequestContext('/api/users'));
$logger->open(new DatabaseQueryContext('SELECT * FROM users'));
$logger->open(new CacheCheckContext('users'));

$logger->close(new CacheResultContext($cached)); // Only closes cache operation
// Missing: close for database and API operations
$logJson = $logger->flush(); // ❌ UnclosedLogicException
```

**✅ Fixed Code:**
```php
$logger->open(new ApiRequestContext('/api/users'));
$logger->open(new DatabaseQueryContext('SELECT * FROM users'));
$logger->open(new CacheCheckContext('users'));

$logger->close(new CacheResultContext($cached));     // Close cache
$logger->close(new QueryResultContext($users));      // Close database  
$logger->close(new ApiResponseContext(200, $users)); // Close API
$logJson = $logger->flush(); // ✅ All operations properly closed
```

## Best Practices

### 1. Use Try-Finally Pattern

```php
$logger->open(new OperationContext());
try {
    // Your operation logic
    $result = $this->performOperation();
    $logger->close(new SuccessContext($result));
} catch (Exception $e) {
    $logger->close(new FailureContext($e));
    throw $e;
}
```

### 2. Helper Methods for Common Patterns

```php
class LoggingService
{
    public function withLogging(AbstractContext $openContext, callable $operation): mixed
    {
        $this->logger->open($openContext);
        
        try {
            $result = $operation();
            $this->logger->close(new SuccessContext($result));
            return $result;
        } catch (Exception $e) {
            $this->logger->close(new FailureContext($e));
            throw $e;
        }
    }
}

// Usage
$result = $loggingService->withLogging(
    new DatabaseOperationContext(),
    fn() => $this->performDatabaseOperation()
);
```

### 3. Validate Stack Balance in Tests

```php
public function testOperationCompletesSuccessfully(): void
{
    $logger = new SemanticLogger();
    
    $logger->open(new TestOperationContext());
    $this->performTestOperation($logger);
    $logger->close(new TestCompletionContext());
    
    // This should not throw UnclosedLogicException
    $logJson = $logger->flush();
    
    $this->assertNotNull($logJson->close);
}
```

## Advanced Debugging

### Get Stack Information

When `UnclosedLogicException` occurs, the exception contains useful debugging information:

```php
try {
    $logJson = $logger->flush();
} catch (UnclosedLogicException $e) {
    echo "Open operations: " . $e->openStackDepth . "\n";
    echo "Last operation: " . $e->lastOperationType . "\n";
    echo "Schema: " . $e->lastOperationSchema . "\n";
}
```

### Common Debugging Steps

1. **Check the stack depth** - How many operations are still open?
2. **Identify the last operation** - What was the most recently opened operation?
3. **Review exception handling** - Are all error paths properly closed?
4. **Verify nested operations** - Are deeply nested operations properly unwound?

## When NOT to Use flush()

**❌ Don't use flush() for debugging incomplete operations:**
```php
// This is wrong - don't try to "see what's in the log" without proper closing
$debugLog = $logger->flush(); // Will throw UnclosedLogicException
```

**✅ Use proper testing and debugging:**
```php
// Add temporary debug logging instead
$logger->event(new DebugCheckpointContext('reached point X'));
```

## Framework Integration

### Laravel/Symfony Error Handling

```php
class SemanticLoggerMiddleware
{
    public function handle($request, Closure $next)
    {
        $logger = app(SemanticLogger::class);
        $logger->open(new RequestContext($request));
        
        try {
            $response = $next($request);
            $logger->close(new ResponseContext($response));
            return $response;
        } catch (Exception $e) {
            $logger->close(new ExceptionContext($e));
            throw $e;
        } finally {
            // Ensure log is flushed even on uncaught exceptions
            try {
                $logJson = $logger->flush();
                Log::info('Request completed', ['semantic_log' => $logJson->toArray()]);
            } catch (UnclosedLogicException $unclosedException) {
                Log::error('Unclosed semantic operations detected', [
                    'unclosed_stack_depth' => $unclosedException->openStackDepth,
                    'last_operation' => $unclosedException->lastOperationType,
                ]);
            }
        }
    }
}
```

## Summary

- **UnclosedLogicException is a programming error** - fix it, don't work around it
- **Always close operations** - both success and failure paths
- **Use try-finally patterns** for guaranteed cleanup
- **Test your logging** - ensure operations are properly balanced
- **Debug systematically** - use exception properties to identify the issue

Remember: Semantic logging is about **complete understanding**. Incomplete operations break that promise and should be treated as bugs.