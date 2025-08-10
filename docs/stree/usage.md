# stree Usage Guide

`stree` (semantic tree) is a command-line tool for visualizing SemanticLogger hierarchical logs as ASCII tree structures.

## Installation

`stree` is included with the SemanticLogger package:

```bash
composer install koriym/semantic-logger
```

The `stree` binary will be available in your `vendor/bin` directory or globally if installed system-wide.

## Basic Usage

```bash
# Basic tree with default 2-level depth
stree logfile.json

# Show help
stree --help
```

## Command Line Options

### Depth Control

```bash
# Show specific depth (levels from root)
stree --depth=5 logfile.json
stree -d 3 logfile.json

# Show complete tree (no depth limits)
stree --full logfile.json  
stree -f logfile.json
```

### Selective Expansion

```bash
# Expand specific context types beyond depth limit
stree --expand=DatabaseQuery logfile.json
stree -e ComplexQuery -e ExternalApi logfile.json

# Combine with depth limits
stree --depth=2 --expand=HttpRequest logfile.json
```

### Performance Filtering

```bash
# Show only operations above time threshold
stree --threshold=10ms logfile.json    # 10 milliseconds
stree --threshold=0.5s logfile.json    # 0.5 seconds
stree -t 100ms logfile.json

# Combine with full tree for performance analysis
stree --full --threshold=50ms slow-operations.json
```

## Output Format

### Tree Structure

stree uses standard tree drawing characters:

```
└── root_operation [125.0ms]              # Last/only child
    ├── first_child [23.0ms]              # Has siblings below
    │   └── nested_operation [8.0ms]      # Nested operation
    ├── second_child [45.0ms]             # Middle child
    └── last_child [52.0ms]               # Final child
```

### Context Information

Different operation types show relevant context information:

#### HTTP Operations
```bash
├── http_request::GET /api/users [15.0ms]
└── http_response::Status 200 [2.0ms]
```

#### Database Operations  
```bash
├── database_connection::localhost/app_db [5.0ms]
│   ├── complex_query::SELECT users [12.0ms]
│   └── complex_query::UPDATE profiles [8.0ms]
```

#### External APIs
```bash
└── external_api_request::PaymentGateway api.payments.com/authorize [150.0ms]
```

#### Cache Operations
```bash
├── cache_operation::get user_123 (HIT) [1.0ms]
└── cache_operation::set session_data (HIT) [2.0ms]
```

#### Authentication
```bash
├── authentication_request::JWT (SUCCESS) [5.0ms]
└── authentication_request::Basic (FAILED) [1.0ms]
```

#### Errors
```bash
└── error::ValidationError: Invalid email format [0.5ms]
```

### Time Display

Execution times are shown in appropriate units:

- **Microseconds**: `[500.0μs]` for times < 1ms
- **Milliseconds**: `[25.0ms]` for times < 1s  
- **Seconds**: `[1.5s]` for times ≥ 1s

### Depth Limiting

When depth limits are reached, truncated branches show `[...]`:

```bash
├── parent_operation [100.0ms]
│   ├── child_operation [50.0ms] 
│   │   └── deep_operation [...] # Truncated at depth limit
```

## Common Use Cases

### 1. Quick Debugging

Get an immediate overview of request flow:

```bash
stree debug.json
```

Output:
```
└── http_request::POST /api/orders [125.0ms]
    ├── authentication_request::JWT (SUCCESS) [5.0ms]
    └── business_logic::order_processing (SUCCESS) [...]
```

### 2. Performance Analysis

Find slow operations:

```bash
stree --threshold=50ms --full performance.json
```

Only shows operations taking more than 50ms, with complete tree depth.

### 3. Database Analysis

Focus on database operations:

```bash
stree --expand=complex_query --expand=database_connection app.json
```

Shows all database operations regardless of depth, while keeping other operations at default depth.

### 4. Deep Debugging

Investigate specific problems:

```bash
stree --depth=10 --expand=error detailed-debug.json
```

Shows deep nesting and expands all error contexts for thorough investigation.

## Integration Examples

### With Demo Scripts

```bash
# Run demo and visualize output
php demo/run.php
stree demo/semantic-log-demo.json

# Analyze just performance metrics
stree --expand=performance_metrics demo/semantic-log-demo.json
```

### Pipeline Usage

```bash
# Generate log and immediately visualize
./generate-log.php | tee output.json && stree output.json

# Filter and analyze in one command
stree --threshold=10ms production.json | grep -E "(database|api)"
```

### Development Workflow

```bash
# Quick check after test run
phpunit && stree test-output.json

# Performance regression detection  
stree --threshold=100ms --full before.json > before.tree
stree --threshold=100ms --full after.json > after.tree
diff before.tree after.tree
```

## Troubleshooting

### Common Errors

**Error: Log file not found**
```bash
stree nonexistent.json
```
Ensure the file path is correct and the file exists.

**Error: Invalid JSON in log file**
```bash
stree malformed.json  
```
The log file contains invalid JSON. Verify it was generated correctly by SemanticLogger.

**No output with threshold**
```bash
stree --threshold=1s fast-operations.json
```
All operations are faster than the threshold. Try a lower threshold or use `--full` to see all operations.

### Empty Output

If stree produces no output:

1. Check if file contains valid SemanticLogger JSON structure
2. Verify timing thresholds aren't filtering everything
3. Ensure the log file isn't empty or malformed

### Performance Tips

- Use `--threshold` for large log files to focus on relevant operations
- Combine `--depth=2` with `--expand` for specific deep analysis
- For very large files, consider preprocessing with `jq` to extract relevant sections

## Exit Codes

- `0`: Success
- `1`: Error (invalid options, file not found, invalid JSON, etc.)

## Related Tools

- `jq`: JSON query processor for preprocessing logs
- `tree`: Unix directory tree display (inspiration for stree)
- SemanticLogger validation: `validate-semantic-log.php`