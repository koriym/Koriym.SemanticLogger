# Profiler Dependency Injection Implementation Guide

## Implementation Steps for BEAR.Resource

### 1. Create VerboseProfiler Implementation Class

Create `src/SemanticLog/VerboseProfiler.php`:

```php
<?php

declare(strict_types=1);

namespace BEAR\Resource\SemanticLog;

use Koriym\SemanticLogger\ProfilerInterface;
use Koriym\SemanticLogger\ProfileResult;

final class VerboseProfiler implements ProfilerInterface
{
    public function stop(string $uri): ProfileResult
    {
        $xhprofFile = null;
        $xdebugTraceFile = null;
        
        // Stop XHProf profiling
        if (function_exists('xhprof_disable')) {
            $xhprofData = xhprof_disable();
            $filename = sprintf(
                '%s/xhprof_%s_%s.xhprof',
                sys_get_temp_dir(),
                str_replace(['/', ':', '?'], '_', $uri),
                uniqid('', true),
            );
            
            if (file_put_contents($filename, serialize($xhprofData)) !== false) {
                $xhprofFile = $filename;
            }
        }
        
        // Stop Xdebug trace
        $traceFile = @xdebug_stop_trace();
        if (is_string($traceFile) && file_exists($traceFile)) {
            $xdebugTraceFile = $traceFile;
        }
        
        return new ProfileResult($xhprofFile, $xdebugTraceFile);
    }
}
```

### 2. Update CompleteContext

Remove existing profiling logic (around lines 66-90) and replace with DI injection:

```php
final class CompleteContext extends AbstractContext
{
    public function __construct(
        ResourceObject $resource, 
        OpenContext $openContext,
        ProfilerInterface $profiler,  // ← Add DI
    ) {
        // Existing domain logic
        $this->uri = (string) $resource->uri;
        $this->code = $resource->code;
        $this->headers = $resource->headers;
        $this->body = $resource->body;
        $this->view = $resource->view ?? '';
        
        // Profiling logic (deduplicated)
        $profileResult = $profiler->stop($openContext->uri);
        $this->xhprofFile = $profileResult->xhprofFile;
        $this->xdebugTraceFile = $profileResult->xdebugTraceFile;
    }
    
    public static function create(
        ResourceObject $resource, 
        OpenContext $openContext,
        ProfilerInterface $profiler,
    ): self {
        return new self($resource, $openContext, $profiler);
    }
}
```

### 3. Update ErrorContext

Remove existing profiling logic (around lines 53-79) and replace with DI injection:

```php
final class ErrorContext extends AbstractContext
{
    public function __construct(
        Throwable $exception,
        string $exceptionId = '',
        ?OpenContext $openContext = null,
        ?ProfilerInterface $profiler = null,  // ← Add DI
    ) {
        $this->exceptionAsString = (string) $exception;
        $this->exceptionId = $exceptionId !== '' ? $exceptionId : $this->createExceptionId();
        
        $this->xhprofFile = null;
        $this->xdebugTraceFile = null;
        
        if ($openContext === null || $profiler === null) {
            return;
        }
        
        // Profiling logic (deduplicated)
        $profileResult = $profiler->stop($openContext->uri);
        $this->xhprofFile = $profileResult->xhprofFile;
        $this->xdebugTraceFile = $profileResult->xdebugTraceFile;
    }
    
    public static function create(
        Throwable $exception,
        string $exceptionId = '',
        ?OpenContext $openContext = null,
        ?ProfilerInterface $profiler = null,
    ): self {
        return new self($exception, $exceptionId, $openContext, $profiler);
    }
}
```

### 4. DI Container Configuration

Ray.Di configuration example:

```php
$this->bind(ProfilerInterface::class)->to(VerboseProfiler::class)->in(Scope::SINGLETON);
```

### 5. Code to Remove (Duplicate Logic)

#### CompleteContext.php (around lines 66-90)
```php
// Remove: Duplicate profiling logic
if (function_exists('xhprof_disable')) {
    $xhprofData = xhprof_disable();
    $filename = sprintf(
        '%s/xhprof_%s_%s.xhprof',
        sys_get_temp_dir(),
        str_replace(['/', ':', '?'], '_', $openContext->uri),
        uniqid('', true),
    );
    
    if (file_put_contents($filename, serialize($xhprofData)) !== false) {
        $this->xhprofFile = $filename;
    }
}

// Remove: Duplicate Xdebug trace logic
$xdebugId = $openContext->getXdebugId();
if ($xdebugId !== null) {
    $traceFile = @xdebug_stop_trace();
    if (is_string($traceFile) && file_exists($traceFile)) {
        $this->xdebugTraceFile = $traceFile;
    }
}
```

#### ErrorContext.php (around lines 53-79)
```php
// Remove: Duplicate profiling logic
if (function_exists('xhprof_disable')) {
    $xhprofData = xhprof_disable();
    $filename = sprintf(
        '%s/xhprof_%s_%s.xhprof',
        sys_get_temp_dir(),
        str_replace(['/', ':', '?'], '_', $openContext->uri),
        uniqid('', true),
    );
    
    if (file_put_contents($filename, serialize($xhprofData)) !== false) {
        $this->xhprofFile = $filename;
    }
}

// Remove: Duplicate Xdebug trace logic
$xdebugId = $openContext->getXdebugId();
if ($xdebugId === null) {
    $traceFile = @xdebug_stop_trace();
    if (is_string($traceFile) && file_exists($traceFile)) {
        $this->xdebugTraceFile = $traceFile;
    }
}
```

## Benefits After Implementation

1. **Eliminate Duplication**: Centralize identical profiling logic
2. **Improve Maintainability**: Profile logic changes only need to be made in one place
3. **Enhance Testability**: ProfilerInterface can be mocked for testing
4. **Separation of Concerns**: Context classes focus on domain logic and profiler result integration
5. **Extensibility**: Easy to add new profiler implementations in the future

## Important Notes

- Maintain existing public properties (`$xhprofFile`, `$xdebugTraceFile`)
- No changes to `jsonSerialize()` method behavior
- ProfilerInterface injection is optional in ErrorContext for backward compatibility
- Preserve existing filename generation logic completely