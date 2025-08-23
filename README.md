# Koriym.SemanticLogger

Type-safe structured logging with JSON schema validation for hierarchical application workflows.

## Motivation

**Bridge the understanding gap between AI and humans** in application performance analysis.

### The Problem: AI vs Human Understanding Gap

Humans understand business context intuitively, but AI struggles with unstructured logs:

```php
// What humans see: "VIP customer order processing"
$logger->info('Query executed in 150ms');          // Human: "Hmm, seems slow for VIP" 
$logger->info('Payment gateway: stripe');          // Human: "Stripe usually works fine"
$logger->info('Inventory check: 2 items');         // Human: "Simple order"  
$logger->info('Order created: ORD_12345');         // Human: "Success, but took too long"

// What AI sees: Disconnected text fragments with no business meaning
// AI cannot understand: customer priority, operation relationships, business impact
```

### The Solution: Shared Semantic Understanding

```php
// Both humans AND AI understand the same business semantics
$logger->open(new ComplexQueryContext($queryType, $customerTier));      // "VIP customer query"
$logger->event(new PaymentContext($gateway, $customerHistory));         // "Trusted customer, reliable gateway"  
$logger->event(new InventoryContext($itemCount, $availability));        // "Simple inventory check"
$logger->close(new QueryResultContext($success, $performanceGrade));    // "Success, but performance below VIP standard"

// Result: AI and humans share the same business understanding
// - Both recognize this as "VIP customer experience degradation"
// - Both understand the performance impact in business terms  
// - Both can prioritize optimization efforts correctly
```

### Why Semantic Logging?

- **Eliminates AI-Human Gap**: Both see the same business context in application behavior
- **Schema-Driven Understanding**: Consistent semantic structure for reliable AI analysis  
- **Business-Focused**: Captures domain logic that matters to both humans and AI
- **Hierarchical Relationships**: Shows how operations connect in ways both can understand
- **Performance + Context**: Technical metrics with business meaning

Perfect for teams who need **AI and humans to understand application behavior identically**.

### How It Works: You Define the Semantic Context

**1) Type and Meaning Clarity**

You create context classes that explicitly define both the data structure and business meaning:

```php
final class VIPCustomerQueryContext extends AbstractContext
{
    const TYPE = 'vip_customer_query';
    const SCHEMA_URL = './schemas/vip_customer_query.json';

    public function __construct(
        public readonly string $customerId,
        public readonly CustomerTier $tier,           // enum: VIP, Premium, Standard
        public readonly QueryComplexity $complexity,  // enum: Simple, Medium, Complex
        public readonly float $expectedSla            // business SLA in seconds
    ) {}
}
```

**Result**: Both AI and humans immediately understand:
- What type of operation this represents
- What business constraints apply (VIP SLA requirements)  
- How to interpret performance data in business terms
- When performance becomes a business issue

**2) Consistent Business Semantics**

```php
// Instead of ambiguous strings
$logger->info("Query took 200ms"); // Fast? Slow? For what?

// You provide explicit business context  
$logger->open(new VIPCustomerQueryContext($id, CustomerTier::VIP, QueryComplexity::Simple, 0.1));
$logger->close(new QueryResultContext($success, $actualTime, $businessImpactLevel));

// Now both AI and human know: "Simple VIP query exceeded SLA by 100ms - high business impact"
```

This approach ensures **semantic precision** - no ambiguity about what data means or why it matters to your business.

## Installation

```bash
composer require koriym/semantic-logger
```

## Basic Usage

### Core Library

```php
use Koriym\SemanticLogger\SemanticLogger;
use YourApp\Contexts\DatabaseQueryContext;

$logger = new SemanticLogger();

// Start operation
$openEntry = $logger->open(new DatabaseQueryContext('SELECT * FROM users'));

// Log events within operation
$logger->event(new QueryExecutionContext($executionTime, $rowCount));

// Close operation with result
$logger->close(new DatabaseQueryContext($queryResult, $success));

// Get structured semantic log (no automatic saving)
$logJson = $logger->flush();

// You decide where to save it
file_put_contents('/var/log/semantic.json', json_encode($logJson));
// OR send to your logging service
$yourLogger->info('semantic_log', ['data' => $logJson]);
// OR store in database
$database->store('semantic_logs', $logJson);
```

### AI-Powered Performance Analysis

The included MCP Server and Claude Code slash commands provide powerful tools for AI-native analysis:

#### Claude Code Slash Commands

**`/semantic-log-list`** - List all semantic profile log files with details  
**`/semantic-log-explain`** - Analyze semantic profile with AI explanation (supports index selection)

```bash
# List all semantic log files
/semantic-log-list

# Explain latest semantic profile
/semantic-log-explain

# Explain 2nd newest profile
/semantic-log-explain 2

# Explain 3rd newest profile  
/semantic-log-explain 3
```

#### CLI Commands

**`bin/semantic-log-list`** - List semantic profile files  
**`bin/semantic-log-explain`** - Analyze semantic profile with AI

```bash
# List files in current directory
./bin/semantic-log-list

# Explain latest profile from demo directory
./bin/semantic-log-explain 1 demo

# Explain 2nd newest profile from custom directory
./bin/semantic-log-explain 2 /path/to/logs
```

#### MCP Server Functions

**`getSemanticProfile`** - Retrieve latest semantic performance profile with AI-optimized prompts  
**`listSemanticProfiles`** - List all available semantic profile log files in the directory  
**`semanticAnalyze`** - Execute PHP script with profiling + automatic AI analysis in one command

#### Interactive Analysis Workflow

```bash
# Step 1: List available semantic profiles
/semantic-log-list

# Step 2: Analyze the semantic profile
/semantic-log-explain

# Step 3: Visualize the results (ask AI)
# "Can you create a diagram of this execution flow?"
```

This workflow provides:
1. **Semantic Profile Discovery** - See all available semantic logs
2. **AI Analysis** - Get comprehensive performance insights from semantic data
3. **Visual Representation** - Tree diagrams showing execution flow

## Setup

### MCP Configuration (Optional)

```bash
# First, generate sample semantic log files
composer demo

# Add to Claude Desktop (use demo directory with sample logs)
claude mcp add semantic-profiler php ./bin/semantic-mcp.php demo

# Verify
claude mcp list
```

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

