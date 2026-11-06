# Koriym.SemanticLogger

Type-safe semantic logging for hierarchical application workflows with record-order JSON output — facts, not intentions; meaning made explicit, never left to inference.

`Koriym.SemanticLogger` records what an application observed as structured JSON. Three calls — `open()` for what is starting, `event()` for what happened while it ran, `close()` for how it ended — produce one document in which every record carries a schema URL and typed context data, so logs stay machine-readable and can be validated, rendered, and inspected without depending on free-form log messages.

A **log** is an ordered list of **records**. A record is one occurrence: a `type` (what it is) and a `context` (what was observed). Kinds are structural — there is no `kind` field:

| A record with… | is… |
|---|---|
| `close` | a completed interval — observed at start (`context`) and end (`close.context`) |
| `log` (even empty), no `close` | an interrupted interval — the end was never observed |
| none of `close`, `log`, `openId` | a point — a single observed fact |
| `openId` (root only) | an orphan close — an end whose start is not in this document |

The structure is fractal: any interval may contain a `log`; the document is the outermost one.

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
- parent-child relationships embedded in the log — no need to correlate open/close pairs by ID

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

The recorded context data is the JSON representation of the context: public properties by default, or the `jsonSerialize()` result when the context implements `JsonSerializable` and that result is a string-keyed array or an object. Other return shapes fall back to public properties. A context that cannot be serialized is replaced by a core-owned placeholder entry, so one bad context never loses the session.

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

`flush()` returns a `LogJson` object. Its public JSON follows this grammar:

```
document := { $schema, log, types?, links? }
log      := record[]
record   := { type,       // what it is; resolves via types or schemaUrl
              context,    // what was observed; conforms to the resolved schema
              log?,       // records observed while open; present (possibly empty) on every interrupted interval
              close?,     // the end observation
              openId?,    // orphan only: id of the missing start
              id?,        // optional; writers MAY omit it where unreferenced, readers MUST NOT require it
              schemaUrl?, // per-record schema (expanded form)
              profile? }  // profiler artifacts (XHProf / Xdebug)
close    := { type, context, id?, schemaUrl?, profile? }
links    := link[]        // RFC 8288 web links to related resources
link     := { rel, href, title?, type? }
```

This writer always emits the expanded form — `id` and `schemaUrl` on every record and close:

```json
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "log": [
    {
      "id": "process_1",
      "type": "process",
      "schemaUrl": "https://example.com/schemas/process.json",
      "context": { "name": "data import" },
      "log": [
        {
          "id": "process_event_1",
          "type": "process_event",
          "schemaUrl": "https://example.com/schemas/process-event.json",
          "context": { "message": "processing started" }
        }
      ],
      "close": {
        "id": "process_result_1",
        "type": "process_result",
        "schemaUrl": "https://example.com/schemas/process-result.json",
        "context": { "status": "success" }
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

The same key at every level means the document is walked with one rule (`recurse(.log[]?)` in jq). Reading it top to bottom is reading the timeline.

#### Order

Array position only — never object member order, timestamps, or counters. The full observation sequence is a depth-first walk: record → its `log` → its `close`. A matched `close` is not a separate record in the sequence but the interval's end observation; its position is determined by structure.

#### Meaning

Every record resolves to exactly one schema: its `schemaUrl`, otherwise the document-level `types[type]` map, otherwise the document is invalid. This writer always emits the expanded form (`schemaUrl` on every record and close, never a `types` key); the compact form (a document-level `types` index and no per-record `schemaUrl`) is a valid, losslessly-equivalent representation that readers (the validator, `stree`) accept but this writer does not produce. When both are present they must agree; a mismatch is an error. Within one document, a `type` always resolves to the same schema: type names are the document's vocabulary.

#### Incomplete Logs

Failures become data; the log never lies and never throws:

- **Interrupted interval** — a `log` (possibly empty) without a `close`; a validator warning. `close` is absent while an open is still unclosed, and its `log` key is present — even empty (`"log": []`) — so an interrupted interval with nothing recorded inside it is still distinguishable from a point.
- **Orphan close** — a root record with `openId`: a close whose open is not in this document, or a second close for an open that is already closed. The tree builder splices it into the root `log` right after the root record being built when it was encountered; there is no top-level `close` list. `SemanticLogger` itself never produces one — it discards misuse closes and records a `semantic_logger_error` instead (see below) — so orphans only appear in documents assembled from raw records.
- **Double close** — an orphan whose `openId` matches an existing record; a validator error.
- **Serialization failure** — the record is replaced in place by an error record; one bad context never loses the session.

`close` is a slot on its interval, not a separate kind in the list: because opens and closes are paired in LIFO order, a matched close always comes last in its scope, so placing it there loses no ordering information.

### The Logger Never Throws

`SemanticLogger` never throws — not on serialization failures, not on protocol misuse, not on empty sessions. Failures become data: core-owned diagnostic entries in the log itself.

- A context that cannot be serialized is replaced by a `semantic_logger_invalid_context` placeholder that keeps the entry's position in the log; nested entries still attach to it. The loss radius of one bad context is that entry — never the session.
- Protocol misuse is recorded as `semantic_logger_error` entries (see below).
- `flush()` always returns a log and always resets: an empty session is a valid empty document (`"log": []`), and an unclosed session returns its live log with an `unclosed_at_flush` diagnostic.
- `toArray()` / `jsonSerialize()` are total, non-destructive snapshots.
- A session records at most `SemanticLogger::DEFAULT_MAX_RECORDS` (1000) opens, events and rejected-close diagnostics; pass another limit as `new SemanticLogger($maxRecords)` (`positive-int`, checked by static analysis). Past the limit the logger stops recording instead of growing without bound: new opens and events are dropped, while every already-recorded open still gets its close, so no recorded interval reads as interrupted; a dropped open left open at flush is not reported as unclosed, since it has no id in the log. `flush()` then appends one root `semantic_logger_truncated` point (`limit`, `dropped`, `firstDroppedType`). It is not a diagnostic — the validator lists it as a warning, and `--fail-on-diagnostics` ignores it. A web request records tens of entries; a batch that logs per row should `flush()` per chunk.

Quality gates live on the reading side: the validator lists diagnostics with their own severity, and `--fail-on-diagnostics` lets CI turn their presence into a failing build.

Note the layer boundary: diagnostics cover the logger's own recording operations. Detecting that a cache server or database is down is monitoring's job, not this library's — record what matters to your domain as your own context types.

### Open / Close Ordering

`open` and `close` must be paired in LIFO order. The logger never throws on violations — it records them as `semantic_logger_error` diagnostic entries with one of these `code` values:

- `close_id_mismatch` — `close()` called with an id other than the innermost open; the stack is left untouched (no guessing), so correct closes still work
- `close_without_open` — `close()` called with no open operation on the stack; the context is discarded and the session continues
- `unclosed_at_flush` — `flush()` called while opens are still pending; the live log is returned and the session resets

Wrap work in `try/finally` so `close()` always runs — not because the logger throws, but because your log should reflect what actually happened.

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

`DevLogger` writes the same public JSON shape returned by `flush()`, which works naturally with `stree`.

## Profiling (XHProf / Xdebug)

Wrap a `SemanticLogger` in `DevSemanticLogger` to attach per-operation profiler artifacts to each matched `close` entry:

```php
use Koriym\SemanticLogger\DevSemanticLogger;
use Koriym\SemanticLogger\SemanticLogger;

$logger = new DevSemanticLogger(new SemanticLogger());
// open() / event() / close() / flush() as usual
```

When XHProf and/or Xdebug are enabled, each `close` gains a `profile` object with `wallTime` plus `xhprofProfile` / `xdebugTrace` segments captured while that operation was active:

```json
"close": {
  "id": "process_result_1",
  "type": "process_result",
  "schemaUrl": "https://example.com/schemas/process-result.json",
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

The envelope (root log) is always validated against the bundled `docs/schemas/semantic-log.json` shipped in the composer dist. You supply a schema directory for your own context schemas — the CLI defaults to `./schemas/` when the second argument is omitted.

```php
use Koriym\SemanticLogger\SemanticLogValidator;

$validator = new SemanticLogValidator();
$validator->validate('path/to/semantic-log.json', 'path/to/context-schemas');
```

Validation checks:

- log structure (kind-aware: interval, interrupted interval, point, orphan close)
- schema URL resolution (`schemaUrl` or the document-level `types` index, and agreement between the two when both are present)
- context payloads against their schemas
- static context metadata: entry types must match `^[a-z_]+$` outside the reserved `semantic_logger_*` namespace, and schema URLs must be absolute URIs or `./schemas/<name>.json`
- diagnostic entries recorded by the logger, listed with their own severity and validated against the bundled core schemas
- detailed validation errors when something does not match

Pass `--fail-on-diagnostics` to exit non-zero when the log contains logger-recorded diagnostic entries — the recommended CI gate:

```bash
vendor/bin/validate-semantic-log.php --fail-on-diagnostics path/to/semantic-log.json path/to/schemas
```

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
- [MIGRATION.md](MIGRATION.md) — 0.8 → 0.9 → 0.10 → 0.11 migration guides (`entries`/`kind`/`close`/`log` key history)
- [CHANGELOG.md](CHANGELOG.md)
