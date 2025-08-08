# Schema URL Specification

## Overview

The `schemaUrl` field is a non-standard but practical extension to JSON Schema used in Koriym.SemanticLogger for AI-powered understanding of semantic log structures.

## Purpose

While not part of the official JSON Schema specification, `schemaUrl` serves a critical purpose for AI tooling and semantic understanding:

- **AI Context**: Provides clear schema references that AI systems can understand and use for interpretation
- **Type Safety**: Links each context object to its corresponding validation schema
- **Documentation**: Self-documenting data structures with explicit schema references
- **Tooling Support**: Enables custom validation tools to locate and apply appropriate schemas

## Standard vs. Practical Approach

### JSON Schema Standard Approach
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$ref": "./schemas/http_request.json"
}
```

**Issues with standard approach:**
- `$ref` is designed for schema composition, not data annotation
- IDEs cannot navigate from data to referenced schemas
- Complex conditional validation (`if/then/else`) not supported by many validators
- Primarily designed for schema authors, not data consumers

### Semantic Logger Approach
```json
{
  "id": "http_request_1",
  "type": "http_request", 
  "schemaUrl": "./schemas/http_request.json",
  "context": {
    "method": "POST",
    "uri": "/api/orders"
  }
}
```

**Benefits of `schemaUrl` approach:**
- Clear, explicit schema references in data
- AI systems can easily identify and use schemas
- Simple validation logic without complex conditionals
- Self-documenting data structures

## Implementation Details

### Field Location
The `schemaUrl` field appears in:
- **Root level**: References the overall semantic log schema
- **Context entries**: References the specific context type schema (open/close/event)

### URL Formats
```json
// Relative path (recommended for local development)
"schemaUrl": "./schemas/http_request.json"

// Absolute URL (for production/published schemas)  
"schemaUrl": "https://koriym.github.io/Koriym.SemanticLogger/schemas/http_request.json"

// External reference (for third-party schemas)
"schemaUrl": "https://example.com/schema/complex-query.json"
```

### Validation Resolution

The custom `SemanticLogValidator` resolves schema URLs using smart mapping:

1. **Relative paths**: `./schemas/http_request.json` → `demo/schemas/http_request.json`
2. **External URLs**: Map known external schemas to local files
3. **Filename extraction**: Extract schema filename for local lookup

## Validation Workflow

```php
// 1. Extract context and schema reference
$context = $contextData['context'];
$schemaUrl = $contextData['schemaUrl'];
$type = $contextData['type'];

// 2. Resolve schema file path
$schemaFile = $this->resolveSchemaPath($schemaUrl, $schemaDir);

// 3. Load schema and validate context
$schema = json_decode(file_get_contents($schemaFile));
$validator = new Validator();
$validator->validate($context, $schema);
```

## Schema File Structure

Each schema file defines validation rules for a specific context type:

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://koriym.github.io/Koriym.SemanticLogger/schemas/http_request.json",
  "title": "HTTP Request Context",
  "type": "object",
  "properties": {
    "method": {
      "type": "string",
      "enum": ["GET", "POST", "PUT", "DELETE", "PATCH", "HEAD", "OPTIONS"]
    },
    "uri": {"type": "string", "format": "uri-reference"},
    "headers": {"type": "object"},
    "userAgent": {"type": "string"},
    "clientIp": {"type": "string", "format": "ipv4"}
  },
  "required": ["method", "uri", "headers", "userAgent", "clientIp"]
}
```

## AI Integration Benefits

### Semantic Understanding
AI systems can use `schemaUrl` references to:
- Understand the structure and meaning of context data
- Provide accurate analysis and insights
- Generate appropriate code or documentation
- Validate data structures programmatically

### Example AI Usage
```markdown
The log contains schemaUrl fields. Refer to these schemas to understand 
the semantic meaning of the data structures:

- http_request context follows ./schemas/http_request.json
- database_connection context follows ./schemas/database_connection.json
- performance_metrics context follows ./schemas/performance_metrics.json
```

## Trade-offs

### Advantages
- ✅ Clear AI understanding
- ✅ Simple validation logic
- ✅ Self-documenting data
- ✅ IDE navigation support potential
- ✅ Practical tooling integration

### Disadvantages  
- ❌ Non-standard JSON Schema extension
- ❌ Not validated by standard JSON Schema validators
- ❌ Custom tooling required for full validation
- ❌ Additional field overhead in data

## Conclusion

The `schemaUrl` approach prioritizes practical AI integration and semantic understanding over strict JSON Schema compliance. While non-standard, it provides significant benefits for AI-powered tools and semantic data processing workflows.

For applications requiring strict JSON Schema compliance, consider using standard `$ref` approaches. For AI integration and semantic tooling, `schemaUrl` provides superior developer and AI experience.