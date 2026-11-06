# Semantic Logger Demo

This demo showcases hierarchical semantic logging with comprehensive context examples.

## Quick Start

Run the semantic logging demonstration:

```bash
composer demo
```

This generates a `semantic-log.json` file with hierarchical logging examples including:
- HTTP request/response cycles
- Authentication flows  
- Database operations
- External API calls
- File processing
- Cache operations
- Error scenarios

## Demo Structure

### Context Examples (`demo/Contexts/`)
- **AuthenticationContext** - Auth request/response/error flows
- **HttpRequestContext/HttpResponseContext** - HTTP lifecycle
- **DatabaseQueryContext** - SQL operations with connections
- **ExternalApiContext** - Third-party API integrations
- **BusinessLogicContext** - Application domain logic
- **CacheOperationContext** - Cache hit/miss scenarios
- **FileProcessingContext** - File operations
- **ErrorContext** - Exception and error handling
- **PerformanceMetricsContext** - Timing and resource usage

### Schema Validation (`demo/schemas/`)
Each context type has a corresponding JSON schema for validation:
- `authentication.json` - Authentication flow schemas
- `http_request.json` - HTTP request validation
- `database_query.json` - Database operation schemas
- `external_api.json` - API call validation
- And more...

### Demo Scripts
- **`demo/run.php`** - Main demonstration with hierarchical operations
- **`demo/e-commerce.php`** - E-commerce workflow example with complex nested operations

## Understanding the Output

The generated `semantic-log.json` follows the Universal Semantic Logger Schema with:

### Hierarchical Structure
Abbreviated example:
```json
{
  "log": [
    {
      "id": "http_request_1",
      "type": "http_request",
      "schemaUrl": "./schemas/http_request.json",
      "log": [
        {"id": "cache_operation_1", "type": "cache_operation", "schemaUrl": "...", "...": "..."},
        {"id": "auth_1", "type": "auth", "schemaUrl": "...", "close": {"...": "..."}}
      ],
      "close": {"id": "http_response_1", "type": "http_response", "...": "..."}
    }
  ]
}
```

### Structural Correlation
- `log` is the timeline of a scope at every level: events and nested opens in the order they were recorded
- Matched `close` entries live directly under their interval
- Kind is structural, not a field: `close` present means a completed interval; `log` present (possibly empty) with no `close` means an interrupted interval; neither means a point (event)
- Orphan closes (no matching open in this document) are inline in the root `log`, each carrying `openId`; there is no root-level `close` list

## Schema Validation

All contexts are validated against their schemas. The framework provides structure while applications define semantic meaning through their own context types and schemas.

## Integration Examples

See how semantic logging integrates with:
- **BEAR.Resource**: Complete REST resource profiling
- **Performance Analysis**: XHProf and Xdebug integration
- **Error Tracking**: Exception flow with hierarchical context
- **Business Intelligence**: Domain-specific operational insights

Run `composer demo` and inspect the output with `vendor/bin/stree` or the validator to explore the semantic structure.
