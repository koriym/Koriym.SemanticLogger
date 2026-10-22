# stree Usage Guide

`stree` (semantic tree) is a command-line tool for visualizing SemanticLogger hierarchical logs as ASCII tree structures.

## Installation

`stree` is included with the SemanticLogger package:

```bash
composer require koriym/semantic-logger
```

The `stree` binary will be available in your `vendor/bin` directory.

## Basic Usage

```bash
# Compact tree (one line per operation, long values truncated)
stree logfile.json

# Show help
stree --help
```

## Command Line Options

| Option | Short | Description |
|--------|-------|-------------|
| `--threshold=T` | `-t` | Time threshold filter such as `10ms` or `0.5s` |
| `--lines=N` | `-l` | Maximum lines for multi-line data (`0` means no limit, default `5`) |
| `--full` | `-f` | Show the full tree with all context keys as leaves |
| `--values` | `-V` | Let registered formatters show values instead of only keys |
| `--json` | | Pretty-print raw JSON passthrough |
| `--help` | `-h` | Display help |

### Full Tree

```bash
stree --full logfile.json
stree -f logfile.json
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

stree uses standard tree drawing characters. A matched close is its own
`└──` line listing the close type and any signals that changed since open:

```
business_logic operation=order_validation success=false
├── http_request method=POST uri=/api/orders [event]
├── authentication_request method=JWT
│   └── authentication_request token=user_12345
└── business_logic success=false→true
```

### Time Display

Execution times are shown in brackets in appropriate units:

- **Microseconds**: `[500.0μs]` for times < 1ms
- **Milliseconds**: `[25.0ms]` for times < 1s
- **Seconds**: `[1.5s]` for times ≥ 1s

## Common Use Cases

### 1. Quick Debugging

Get an immediate overview of request flow:

```bash
stree debug.json
```

### 2. Performance Analysis

Find slow operations:

```bash
stree --threshold=50ms --full performance.json
```

Only shows operations taking more than 50ms, with every context key expanded (`--full`).

## Integration Examples

### With Demo Scripts

```bash
# Run demo and visualize output
php demo/run.php
stree demo/semantic-log-demo.json
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

1. Check if the file contains a valid SemanticLogger JSON structure (`log` at the root)
2. Verify timing thresholds aren't filtering everything
3. Ensure the log file isn't empty or malformed

## Exit Codes

- `0`: Success
- `1`: Error (invalid options, file not found, invalid JSON, etc.)

## Related Tools

- `jq`: JSON query processor for preprocessing logs
- `tree`: Unix directory tree display (inspiration for stree)
- SemanticLogger validation: `validate-semantic-log.php`
