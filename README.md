# Koriym.SemanticLogger

Type-safe structured logging with JSON schema validation for hierarchical application workflows.

**Designed to provide equal context to both AI systems and human developers for comprehensive system understanding.**

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

This structure enables understanding not just *what* happened, but *why* it happened and *how* it relates to the intended operation.

**Best Practice: Use alongside traditional logging**
- Traditional logs for quick debugging messages and stack traces
- Semantic logs for complex debugging, compliance auditing, and system analysis
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
- **OpenId correlation** for complete request-response traceability
- **Schema relations** for debugging context via RFC 8288 compliant linking
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

final class ProcessContext extends AbstractContext
{
    public const TYPE = 'process';
    public const SCHEMA_URL = 'https://example.com/schemas/process.json';
    
    public function __construct(
        public readonly string $name,
    ) {}
}

final class EventContext extends AbstractContext
{
    public const TYPE = 'event';
    public const SCHEMA_URL = 'https://example.com/schemas/event.json';
    
    public function __construct(
        public readonly string $message,
    ) {}
}

final class ResultContext extends AbstractContext
{
    public const TYPE = 'result';
    public const SCHEMA_URL = 'https://example.com/schemas/result.json';
    
    public function __construct(
        public readonly string $status,
    ) {}
}
```

### 2. Log with Semantic Structure: Intent → Events → Result

```php
use Koriym\SemanticLogger\SemanticLogger;

$logger = new SemanticLogger();

// OPEN: Declare intent - what we plan to do
$processId = $logger->open(new ProcessContext('data processing'));

// EVENT: What happened during execution
$logger->event(new EventContext('processing started'));

// CLOSE: What actually occurred - the result
$logger->close(new ResultContext('success'), $processId);

// Optional: Add relations for debugging context
$relations = [
    ['rel' => 'related', 'href' => 'https://github.com/example/my-app', 'title' => 'Source Code Repository'],
    ['rel' => 'describedby', 'href' => 'https://example.com/db/schema/processes.sql', 'title' => 'Database Schema']
];

// Get structured log with complete intent→result mapping
$logJson = $logger->flush($relations);
echo json_encode($logJson, JSON_PRETTY_PRINT);
```

### 3. Output Structure

The semantic structure captures the complete intent→result flow with **openId correlation**:

```json
{
  "$schema": "https://koriym.github.io/semantic-logger/schemas/semantic-log.json",
  "open": {
    "id": "process_1",
    "type": "process",
    "$schema": "https://example.com/schemas/process.json",
    "context": {
      "name": "data processing"
    }
  },
  "events": [
    {
      "type": "event",
      "$schema": "https://example.com/schemas/event.json",
      "context": {
        "message": "processing started"
      },
      "openId": "process_1"
    }
  ],
  "close": {
    "type": "result",
    "$schema": "https://example.com/schemas/result.json",
    "context": {
      "status": "success"
    },
    "openId": "process_1"
  },
  "relations": [
    {
      "rel": "related",
      "href": "https://github.com/example/my-app",
      "title": "Source Code Repository"
    },
    {
      "rel": "describedby", 
      "href": "https://example.com/db/schema/processes.sql",
      "title": "Database Schema"
    }
  ]
}
```

**Structure Meaning:**
- **open**: Intent and planned operations (hierarchical) - each has unique `id`
- **events**: Occurrences during execution (flat list) - linked via `openId` to their operation context
- **close**: Actual results and outcomes (matches open hierarchy) - linked via `openId` to corresponding open operation
- **openId**: Correlation field that links events and close entries to their originating open operation
- **schemaUrl**: JSON Schema URL for validation and documentation
- **relations**: Optional RFC 8288 compliant links (related resources, schemas, etc.)

**OpenId Correlation Benefits:**
- **Request Tracing**: Easily identify which events belong to which operation in complex nested workflows
- **Debugging**: Quickly trace the flow from intent (open) → events → result (close) for any operation
- **Monitoring**: Track operation completion rates and identify unclosed operations in production logs
- **Compliance**: Maintain audit trails with clear operation boundaries for regulatory requirements

## Documentation

📖 **[Schema Documentation](docs/schemas/README.md)** - JSON Schema validation and RFC 8288 link relations

## Architecture

- **AbstractContext**: Base class for type-safe context objects
- **SemanticLogger**: Main logger with hierarchical operations
- **LogJson**: Immutable structured log output
- **Types.php**: Domain type definitions for static analysis

## License

MIT License. See LICENSE file for details.
