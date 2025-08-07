# Koriym.SemanticLogger

Type-safe structured logging with JSON schema validation for hierarchical application workflows.

## AI-Native Analysis with MCP Server

**Realizing Tim Berners-Lee's Semantic Web Vision** - structured data that AI can understand and reason about autonomously.

```bash
# Install and run MCP server for Claude Code integration
composer require koriym/semantic-logger
php vendor/koriym/semantic-logger/bin/server.php /tmp
```

### AI-Powered Performance Analysis

The included MCP Server provides two powerful tools for AI-native analysis:

**`getSemanticProfile`** - Retrieve latest semantic performance profile with AI-optimized prompts  
**`semanticAnalyze`** - Execute PHP script with profiling + automatic AI analysis in one command

### MCP Server Configuration

Add to your Claude Desktop config (`~/Library/Application Support/Claude/claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "semantic-profiler": {
      "command": "php",
      "args": [
        "vendor/koriym/semantic-logger/bin/server.php",
        "/tmp"
      ]
    }
  }
}
```

See [docs/mcp-setup.md](docs/mcp-setup.md) for detailed configuration options.

### Semantic Web Architecture

**Everything is Linked for Machine Understanding:**

1. **JSON Schema URLs** - Every context includes `$schema` for semantic validation
2. **JSON Pointer Links** - Schema properties include `links` arrays pointing to specifications
3. **RFC 8288 Relations** - Standard `rel` attributes (describedby, related, canonical) for semantic connections
4. **AI Interview Process** - Regular AI feedback to minimize non-semantic noise

**Example Schema with Semantic Links:**
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "properties": {
    "method": {
      "type": "string",
      "enum": ["GET", "POST", "PUT", "DELETE"]
    }
  },
  "links": [
    {
      "anchor": "#/properties/method",
      "rel": "describedby", 
      "href": "https://tools.ietf.org/html/rfc7231#section-4",
      "title": "HTTP Methods (RFC 7231)"
    }
  ]
}
```

**Semantic Web Mindset:**
- **Everything is machine-readable** - No human-only documentation
- **Links over comments** - Semantic relationships through URIs, not prose
- **AI as quality gatekeeper** - Regular AI interviews to identify non-semantic patterns
- **Schema-first design** - Structure meaning before implementation
- **Minimize semantic noise** - Every field must have clear semantic purpose

This approach enables AI to autonomously understand system behavior, diagnose issues, and provide architectural insights beyond basic metrics.

### DevLogger for Development

Development logging with AI-native analysis prompt generation:

```php
use Koriym\SemanticLogger\DevLogger;
use Koriym\SemanticLogger\SemanticLogger;

// Initialize with log directory (defaults to system temp)
$devLogger = new DevLogger('/path/to/logs');

// Output semantic logs with AI analysis prompts
$semanticLogger = new SemanticLogger();
$devLogger->log($semanticLogger);
```

Creates two files:
- `semantic-dev-*.json` - Structured semantic log data
- `semantic-dev-*-prompt.md` - AI-optimized analysis prompt with embedded JSON

Perfect for development debugging and AI-assisted performance analysis.

## Self-Proving Responses

Every response becomes self-proving by logging **how** it was generated. When your API returns "restaurant menu list", the semantic log proves **why** those specific items were returned through the complete chain of web API calls, database queries, and business logic.

**Response (what):** `{"menu": ["pasta", "pizza", "salad"]}`  
**Semantic Log (how/why):** Complete proof of why these 3 items were returned

Provides equal context to both AI systems and human developers for comprehensive system understanding.

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
- Traditional logs for quick debugging and stack traces
- Semantic logs for complex workflows and system analysis

## Use Cases

- **Development & Debugging** - Complex workflow tracing with intent vs result analysis
- **Compliance & Auditing** - GDPR, SOX, HIPAA compliance with complete audit trails
- **Security & Monitoring** - Track data modifications and detect anomalous behavior
- **Business Intelligence** - Analyze behavior patterns and optimize processes

## Features

- **Type-safe context objects** with const properties
- **Hierarchical logging** with open/event/close patterns
- **JSON Schema validation** for log entries

Structured, semantic, type-safe logging with web linking enables deep understanding for humans, AI, and data science.

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

The semantic structure captures the complete intent→result flow with **hierarchical nesting**:

```json
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "open": {
    "type": "process",
    "schemaUrl": "https://example.com/schemas/process.json",
    "context": {
      "name": "data processing"
    },
    "events": [
      {
        "type": "event",
        "schemaUrl": "https://example.com/schemas/event.json",
        "context": {
          "message": "processing started"
        }
      }
    ],
    "close": {
      "type": "result",
      "schemaUrl": "https://example.com/schemas/result.json",
      "context": {
        "status": "success"
      }
    }
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
- **open**: Intent and planned operations with nested events and close results (hierarchical structure)
- **events**: Occurrences during execution (nested within their operation context)
- **close**: Actual results and outcomes (nested within the corresponding open operation)
- **schemaUrl**: JSON Schema URL for validation and documentation
- **relations**: Optional RFC 8288 compliant links (related resources, schemas, etc.)

**Hierarchical Nesting Benefits:**
- **Request Tracing**: Clear operation boundaries through nested structure in complex workflows
- **Debugging**: Trace the flow from intent (open) → events → result (close) within each operation context
- **Monitoring**: Track operation completion and identify unclosed operations
- **Compliance**: Maintain audit trails with clear operation boundaries

## Semantic Log Validation

Validate your semantic logs with our custom validator that understands the AI-human understanding bridge approach:

### Command Line Usage

```bash
# Validate a semantic log against schema directory
php vendor/bin/validate-semantic-log.php path/to/semantic-log.json path/to/schemas/

# Example with demo
composer demo  # Generates demo.json and validates it automatically
```

### PHP Usage

```php
use Koriym\SemanticLogger\SemanticLogValidator;

$validator = new SemanticLogValidator();

try {
    $validator->validate('path/to/semantic-log.json', 'path/to/schemas/');
    echo "✅ All contexts validate successfully!\n";
} catch (RuntimeException $e) {
    echo "❌ Validation failed: " . $e->getMessage() . "\n";
}
```

### Validation Features

- **Hierarchical Structure Validation**: Validates deeply nested open/close/event structures
- **Schema URL Resolution**: Supports both relative (`./schemas/`) and absolute URLs
- **Comprehensive Error Reporting**: Detailed violation messages with JSON Schema links
- **AI-Human Bridge Support**: Validates the `schemaUrl` field approach for AI understanding

### Sample Validation Output

```
✅ open (http_request) validates against ./schemas/http_request.json
✅ open.open.open (database_query) validates against ./schemas/database_query.json
✅ events[0] (cache_operation) validates against ./schemas/cache_operation.json
✅ events[1] (performance_metrics) validates against ./schemas/performance_metrics.json
✅ All contexts validate successfully!
```

## Documentation

**[Schema Portal](https://koriym.github.io/Koriym.SemanticLogger/)** - AI-native semantic schema portal with comprehensive documentation

