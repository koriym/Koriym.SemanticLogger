# Semantic Logger Demo

This demo showcases hierarchical semantic logging with comprehensive context examples and MCP server integration for AI-powered log analysis.

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

## MCP Server Analysis

After generating logs, use Claude Code with MCP server for AI-powered analysis:

1. **Generate demo logs**:
   ```bash
   composer demo
   ```

2. **Start Claude Code**:
   ```bash
   claude
   ```

3. **Analyze logs with AI**:
   ```
   /profile list
   ```

The MCP server provides these commands for log analysis:
- `/profile` - Analyze the most recent semantic log
- `/profile list` - List all available semantic logs
- `/profile <filename>` - Analyze specific log file

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

### AI Analysis Benefits
With this hierarchical structure, AI can:
- **Trace causality**: "Why did the request take 680ms?" → "Auth took 520ms"
- **Identify bottlenecks**: "What's the slowest operation?" → "External API call"
- **Optimize performance**: "If I fix the database query, what improves?"
- **Debug errors**: "Which operation caused the failure?"

## Schema Validation

All contexts are validated against their schemas. The framework provides structure while applications define semantic meaning through their own context types and schemas.

## Integration Examples

See how semantic logging integrates with:
- **BEAR.Resource**: Complete REST resource profiling
- **Performance Analysis**: XHProf and Xdebug integration
- **Error Tracking**: Exception flow with hierarchical context
- **Business Intelligence**: Domain-specific operational insights

Run `composer demo` and then use Claude Code's `/profile` commands to experience AI-powered semantic log analysis!