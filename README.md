# Koriym.SemanticLogger

Type-safe structured logging with JSON schema validation for hierarchical application workflows.

**Built to achieve equal understanding between AI and humans through complete system transparency.**

## Beyond Traditional Logging: Semantic Structure

Traditional logging captures **level and message for humans**:
```
[INFO] User query executed: SELECT * FROM users WHERE id = 123
[DEBUG] Query took 12.5ms, returned 1 row
```

Semantic logging captures **data structure AND meaning structure**:
- **Open**: "what we plan to do" (intent)
- **Event**: "what happened during execution" (occurrences)
- **Close**: "what actually occurred" (result)

This meaning structure enables both AI and humans to understand not just *what* happened, but *why* it happened and *how* it relates to the intended operation.

**Best Practice: Use alongside traditional logging**
- Traditional logs for quick debugging messages and stack traces
- Semantic logs for deep system understanding, complex debugging, compliance, and AI analysis
- Semantic logs excel at tracing multi-step operations and understanding system behavior
- Combine both for comprehensive observability

## Use Cases

- **Development & Debugging** - Complex workflow tracing with intent vs result analysis
- **Compliance & Auditing** - GDPR, SOX, HIPAA compliance with complete audit trails
- **Security & Monitoring** - Track data modifications and detect anomalous behavior
- **Business Intelligence** - Analyze behavior patterns and optimize processes

## Features

- **Type-safe context objects** with const properties
- **JSON Schema validation** for log entries
- **Hierarchical logging** with open/event/close patterns
- **Schema relations** for complete system transparency via ALPS/JSON-LD style linking
- **Zero configuration** - just extend AbstractContext
- **Flush pattern** for one-time log consumption
- **Structured output** compatible with observability tools

## Installation

```bash
composer require koriym/semantic-logger
```

## Quick Start

### 1. Define Context Classes

Create type-safe context classes by extending `AbstractContext`:

```php
use Koriym\SemanticLogger\AbstractContext;

final class ApiRequestContext extends AbstractContext
{
    public const TYPE = 'api_request';
    public const SCHEMA_URL = 'https://example.com/schemas/api_request.json';
    
    public function __construct(
        public readonly string $endpoint,
        public readonly string $method,
        public readonly array $headers = [],
    ) {}
}

final class DatabaseQueryContext extends AbstractContext
{
    public const TYPE = 'database_query';
    public const SCHEMA_URL = 'https://example.com/schemas/database_query.json';
    
    public function __construct(
        public readonly string $query,
        public readonly array $params,
        public readonly float $executionTime,
    ) {}
}
```

### 2. Log with Semantic Structure: Intent → Events → Result

```php
use Koriym\SemanticLogger\SemanticLogger;

$logger = new SemanticLogger();

// OPEN: Declare intent - what we plan to do
$logger->open(new ApiRequestContext('/api/users/123', 'GET'));

    // OPEN: Nested operation intent
    $logger->open(new DatabaseQueryContext(
        'SELECT * FROM users WHERE id = ?', 
        [123],
        'users'  // table name
    ));
    
    // EVENT: What happened during execution
    $logger->event(new CacheHitContext('user_123'));
    
    // CLOSE: What actually occurred - results and metrics
    $logger->close(new QueryResultContext(
        rowCount: 1,
        executionTimeMs: 12.5,
        resultSet: [['id' => 123, 'name' => 'John']],
        indexesUsed: ['PRIMARY']
    ));

// CLOSE: Final result of the main operation
$logger->close(new ApiResponseContext(200, ['user_id' => 123]));

// Get structured log with complete intent→result mapping
$logJson = $logger->flush();
echo json_encode($logJson, JSON_PRETTY_PRINT);
```

### 3. Output Structure

The semantic structure captures the complete intent→result flow:

```json
{
  "$schema": "https://koriym.github.io/semantic-logger/schemas/semantic-log.json",
  "open": {
    "type": "your_open_operation",
    "$schema": "https://example.com/schemas/your_open.json",
    "context": {
      "your_intent_parameters": "what_we_plan_to_do"
    },
    "open": {
      "type": "your_nested_operation", 
      "$schema": "https://example.com/schemas/your_nested.json",
      "context": {
        "your_nested_intent": "sub_operation_plan"
      }
    }
  },
  "events": [
    {
      "type": "your_event",
      "$schema": "https://example.com/schemas/your_event.json", 
      "context": {
        "your_event_data": "what_happened_during_execution"
      }
    }
  ],
  "close": {
    "type": "your_close_result",
    "$schema": "https://example.com/schemas/your_close.json",
    "context": {
      "your_actual_outcome": "what_actually_occurred",
      "your_metrics": "execution_results"
    }
  }
}
```

**Structure Meaning:**
- **open**: Intent and planned operations (hierarchical)
- **events**: Occurrences during execution (flat list)  
- **close**: Actual results and outcomes (matches open hierarchy)
- **$schema**: JSON Schema URL for validation and documentation

## Documentation

📖 **[Complete Manual](docs/manual.md)** - Comprehensive guide including:
- Schema Relations for complete system transparency
- Real-world examples (GDPR, Security, SOX compliance)
- JSON Schema creation and validation tools
- Advanced usage patterns and PSR-3 integration

## Architecture

- **AbstractContext**: Base class for type-safe context objects
- **SemanticLogger**: Main logger with hierarchical operations
- **LogJson**: Immutable structured log output
- **Types.php**: Domain type definitions for static analysis

## License

MIT License. See LICENSE file for details.
