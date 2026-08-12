# Koriym.SemanticLogger

Type-safe semantic logging for hierarchical application workflows with tree-shaped JSON output.

`Koriym.SemanticLogger` records three kinds of facts as structured JSON:

- `open`: what is starting
- `event`: what happened while it was running
- `close`: how it ended

Each entry carries a schema URL and typed context data, so logs stay machine-readable and can be validated, rendered, and inspected without depending on free-form log messages.

The public log contract is structural: matched `close` entries and scoped `events` are nested under the `open` entry they belong to. Once serialized to JSON, normal operation results are read from paths such as `open[0].close` — not from a separate top-level close list.

## Installation

```bash
composer require koriym/semantic-logger
```

## Core Model

Semantic logs are built from matching `open` / `close` pairs plus optional `event`s:

```text
request open
  database_query event
  cache_lookup event
request close
```

This gives you:

- explicit operation boundaries
- nested workflow structure
- intent vs outcome
- schema-backed context instead of ad-hoc strings
- parent-child relationships embedded in the tree — no need to correlate open/close pairs by ID

Optional RFC 8288 links can be attached at flush time when you want to point to related resources such as source code, schemas, or external specs.

## Quick Start

### 1. Define Context Classes

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

final class ProcessEventContext extends AbstractContext
{
    public const TYPE = 'process_event';
    public const SCHEMA_URL = 'https://example.com/schemas/process-event.json';

    public function __construct(
        public readonly string $message,
    ) {}
}

final class ProcessResultContext extends AbstractContext
{
    public const TYPE = 'process_result';
    public const SCHEMA_URL = 'https://example.com/schemas/process-result.json';

    public function __construct(
        public readonly string $status,
    ) {}
}
```

### 2. Log a Workflow

```php
use Koriym\SemanticLogger\SemanticLogger;

$logger = new SemanticLogger();

$processId = $logger->open(new ProcessContext('data import'));
$logger->event(new ProcessEventContext('processing started'));
$logger->close(new ProcessResultContext('success'), $processId);

$links = [
    [
        'rel' => 'describedby',
        'href' => 'https://example.com/specs/import-flow',
        'title' => 'Import Flow Specification',
    ],
];

$log = $logger->flush($links);

echo json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

### 3. Output Shape

`flush()` returns a `LogJson` object. Its public JSON shape is tree-oriented: each `open` node contains nested child `open`s, scoped `events`, and its matching `close` object. Top-level `events` and `close` are reserved for root-scope or orphan diagnostics when structural placement is not possible, so normal consumers should treat the tree as the source of truth.

```json
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "open": [
    {
      "id": "process_1",
      "type": "process",
      "schemaUrl": "https://example.com/schemas/process.json",
      "context": {
        "name": "data import"
      },
      "events": [
        {
          "id": "process_event_1",
          "type": "process_event",
          "schemaUrl": "https://example.com/schemas/process-event.json",
          "context": {
            "message": "processing started"
          }
        }
      ],
      "close": {
        "id": "process_result_1",
        "type": "process_result",
        "schemaUrl": "https://example.com/schemas/process-result.json",
        "context": {
          "status": "success"
        }
      }
    }
  ],
  "links": [
    {
      "rel": "describedby",
      "href": "https://example.com/specs/import-flow",
      "title": "Import Flow Specification"
    }
  ]
}
```

### Open / Close Ordering

`open` and `close` must be paired in LIFO order. In the default strict mode, violations raise exceptions from `Koriym\SemanticLogger\Exception`:

- `InvalidOperationOrderException` — `close()` called with an id other than the innermost open
- `NoOpenOperationsException` — `close()` called with no open operation on the stack
- `UnclosedLogicException` — `flush()` called while opens are still pending

Wrap work in `try/finally` so `close()` always runs, even on error paths.

## Strict and Total Modes

`SemanticLogger` is strict by default. Strict mode is intended for development and tests: invalid metadata, serialization failures, ordering mistakes, and incomplete sessions are surfaced immediately.

```php
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerMode;

$strictLogger = new SemanticLogger();
$totalLogger = new SemanticLogger(SemanticLoggerMode::Total);
```

Total mode is intended for boundaries where logging must remain total. Instead of letting a caller error interrupt the application, it records a core-owned placeholder at the attempted entry's tree position and emits a `semantic_logger_error` diagnostic event. Core diagnostic payloads use bundled schemas and contain only inert JSON values, so reporting a serialization failure cannot recurse into user serialization.

Context values are frozen as an inert JSON tree at each write. Nested `JsonSerializable` values therefore run once, during that write, and are never invoked again by a snapshot or flush. In PHP snapshots, nested JSON objects are represented as `stdClass` rather than associative arrays; the serialized JSON shape is unchanged. Consumers that inspect `toArray()` directly should treat nested context values as JSON-shaped data.

| Condition | Strict | Total |
|-----------|--------|-------|
| Invalid `TYPE` or `SCHEMA_URL` | `InvalidContextTypeException` or `InvalidSchemaUrlException`; no mutation | Inline `semantic_logger_invalid_context` placeholder plus diagnostic |
| Context serialization failure | Original exception; no mutation | Inline placeholder plus exception diagnostic |
| `close()` without an open or with the wrong LIFO id | Ordering exception; no mutation | Diagnostic; open stack remains unchanged |
| Valid close whose context cannot serialize | Original exception; open remains retryable | Placeholder close is committed and the span is closed |
| Sessionless `flush()` | `NoLogSessionException` | Valid empty log with `"open": []` |
| `flush()` with unclosed spans | `UnclosedLogicException` | Incomplete opens plus `unclosed_at_flush` diagnostic |

Every document produced by `SemanticLogger` declares the mode that produced it as `"mode": "strict"|"total"` in the envelope. In a total-mode document, the absence of diagnostic entries is proof of a clean session; a document without `mode` carries no such proof. `NullSemanticLogger` omits the field by design — its document proves nothing about how it was produced.

Consumer context metadata must follow these rules:

- `TYPE` is non-empty and matches `^[a-z_]+$`.
- `semantic_logger_*` is reserved for core placeholders and diagnostics.
- `SCHEMA_URL` is an absolute URI or a relative `./schemas/<name>.json` path.

## Session Lifecycle

A session is the interval between calls to `flush()` and begins implicitly with the first `open()`, `event()`, or diagnostic. Event-only sessions are valid and serialize with the required empty shape `"open": []`.

Both modes reset all session state whenever `flush()` returns or throws. Writes after that call begin a fresh session and operation ids restart from one. This makes one logger instance per worker safe when every request or message boundary flushes in `finally`.

All writers participating in one boundary must share the same logger instance, and exactly one owner must flush it. Multiple owners flushing the same instance split one logical session silently.

`toArray()` and `jsonSerialize()` are non-destructive snapshots:

- Strict mode throws on a sessionless or incomplete session and preserves state. Mid-session dumps are therefore intentionally unavailable in strict mode.
- Total mode returns an empty sessionless snapshot or an honest incomplete tree. Its `unclosed_at_flush` diagnostic is synthesized for that snapshot only, so repeated snapshots do not accumulate events.

`NullSemanticLogger` follows the same id protocol: `open()` returns unique per-session ids (`noop_1`, `noop_2`, …), `close()` accepts them unconditionally, and `flush()` resets the counter.

### Try It

A runnable end-to-end example lives under `demo/` (see `demo/run.php`, `demo/e-commerce.php`). `composer demo` runs it with XHProf + Xdebug enabled, validates the output, and renders it with `stree`.

## Development Logs

Use `DevLogger` to write logs to disk during development:

```php
use Koriym\SemanticLogger\DevLogger;
use Koriym\SemanticLogger\SemanticLogger;

$semanticLogger = new SemanticLogger();
$devLogger = new DevLogger(__DIR__ . '/var/log');

$operationId = $semanticLogger->open(new ProcessContext('data import'));
$semanticLogger->event(new ProcessEventContext('processing started'));
$semanticLogger->close(new ProcessResultContext('success'), $operationId);

$devLogger->log($semanticLogger);
```

Each call writes a file named `semantic-dev-<timestamp>-<pid>-<uniqid>.json` (microsecond timestamp, PID, and `uniqid()` suffix) to the given directory, or to `sys_get_temp_dir()` when no directory is passed.

`DevLogger` writes the same public tree JSON shape returned by `flush()`, which works naturally with `stree`. Top-level `events` and `close` are reserved for root-scope or orphan diagnostics when structural placement is not possible.

## Profiling (XHProf / Xdebug)

Wrap a `SemanticLogger` in `DevSemanticLogger` to attach per-operation profiler artifacts to each matched `close` entry:

```php
use Koriym\SemanticLogger\DevSemanticLogger;
use Koriym\SemanticLogger\SemanticLogger;

$logger = new DevSemanticLogger(new SemanticLogger());
// open() / event() / close() / flush() as usual
```

When XHProf and/or Xdebug are enabled, each `close` in the tree gains a `profile` object with `wallTime` plus `xhprofProfile` / `xdebugTrace` segments captured while that operation was active:

```json
"close": {
  "id": "process_result_1",
  "type": "process_result",
  "context": { "status": "success" },
  "profile": {
    "wallTime": 0.0123,
    "xhprofProfile": [{ "path": "/tmp/xhprof-...xhprof" }],
    "xdebugTrace":   [{ "path": "/tmp/trace-...xt" }]
  }
}
```

Segments are attributed only to the operation they ran inside — the parent's profile pauses while a child is open. No extensions required to use the logger itself; `DevSemanticLogger` simply skips profile attachment when XHProf / Xdebug are not loaded.

## Validate Semantic Logs

```bash
vendor/bin/validate-semantic-log.php path/to/semantic-log.json [path/to/schemas]
```

The envelope (root tree) is always validated against the bundled `docs/schemas/semantic-log.json` shipped in the composer dist. Core placeholder and diagnostic contexts also resolve against their bundled schemas. You supply a schema directory for your own context schemas — the CLI defaults to `./schemas/` when the second argument is omitted.

```php
use Koriym\SemanticLogger\SemanticLogValidator;

$validator = new SemanticLogValidator();
$validator->validate('path/to/semantic-log.json', 'path/to/context-schemas');
```

Validation checks:

- nested log structure
- schema URL resolution
- context payloads against their schemas
- detailed validation errors when something does not match

## Semantic Tree Visualizer

`vendor/bin/stree` renders semantic logs as a readable tree:

```bash
vendor/bin/stree debug.json
vendor/bin/stree --full debug.json
vendor/bin/stree --threshold=10ms slow.json
vendor/bin/stree --json debug.json
```

### Options

| Option | Short | Description |
|--------|-------|-------------|
| `--threshold=T` | `-t` | Time threshold filter such as `10ms` or `0.5s` |
| `--lines=N` | `-l` | Maximum lines for multi-line data (`0` means no limit) |
| `--full` | `-f` | Show the full tree |
| `--values` | `-V` | Let registered formatters show values instead of only keys |
| `--json` | | Pretty-print raw JSON |
| `--help` | `-h` | Display help |

### Rendering in process

`stree` is a thin CLI over the same renderer you can call directly. `LogJson::render()` takes a `LogRendererInterface` and hands the log to it (double dispatch), so the log never needs to know the output format — you swap the renderer instead:

```php
use Koriym\SemanticLogger\Stree\TreeRenderer;
use Koriym\SemanticLogger\Stree\RenderConfig;

$log = $logger->flush();

echo $log->render(new TreeRenderer());                              // compact tree
echo $log->render(new TreeRenderer(new RenderConfig(true, 0.0, 5))); // full tree
```

This keeps the tree compact for both humans (visual parent/child structure) and AI consumers (far fewer tokens than the raw JSON). Implement `LogRendererInterface` to add your own output format (e.g. Markdown or Mermaid) without changing the logger.

## Use Cases

- trace nested application workflows
- compare planned work vs actual result
- keep audit-friendly structured records
- inspect development logs without parsing free-form text
- render semantic logs as trees during debugging

## Documentation

- [Schema Portal](https://koriym.github.io/Koriym.SemanticLogger/)
- [CHANGELOG.md](CHANGELOG.md)
