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
```json
{
  "open": {"id": "http_request_1", "type": "http_request"},
  "open": {"id": "auth_1", "openId": "http_request_1"},
  "close": {"id": "auth_complete_1", "openId": "auth_1"},
  "close": {"id": "http_response_1", "openId": "http_request_1"}
}
```

### OpenId Correlation
- Every `close` and `event` references its parent `open` via `openId`  
- Creates parent-child relationships for performance attribution
- Enables complete request flow tracing

## Schema Validation

All contexts are validated against their schemas. The framework provides structure while applications define semantic meaning through their own context types and schemas.

## Integration Examples

See how semantic logging integrates with:
- **BEAR.Resource**: Complete REST resource profiling
- **Performance Analysis**: XHProf and Xdebug integration
- **Error Tracking**: Exception flow with hierarchical context
- **Business Intelligence**: Domain-specific operational insights

Run `composer demo` and inspect the output with `vendor/bin/stree` or the validator to explore the semantic structure.
