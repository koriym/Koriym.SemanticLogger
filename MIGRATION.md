# Migration Guide: 0.8 / 1.x → 0.9

0.9 rebuilds the logger around a single behavior: **the logger never throws**.
Failures and protocol misuse become core-owned diagnostic entries in the log
itself, and quality gates move to the reading side (the validator and CI).

## Unreleased: closes in the record list, diagnostic `code`

The public JSON tree is unchanged. Two things move underneath it:

| 0.10.0 | Unreleased |
|---|---|
| `new LogJson($schemaUrl, $records, $closes, $links)` | `new LogJson($schemaUrl, $records, $links)` — closes are records too, at the position they were recorded |
| `$log->closes` | gone; filter `CloseEntry` instances from `$log->records` |
| a close is an `EventEntry` with `openId` and `profile` | `CloseEntry(id, type, schemaUrl, context, openId, profile)` — `openId` is required |
| `EventEntry(id, type, schemaUrl, context, openId, profile)` | `EventEntry(id, type, schemaUrl, context, openId)` — events never carried a profile in the output |
| `LogTreeBuilder::build($records, $closes)` | `build($records)` |
| diagnostic context `kind` (`close_without_open`, …) | `code` — `kind` now means only the entry kind (`open` / `event`) |
| `semantic_logger_error.json` / `semantic_logger_invalid_context.json` require `kind` | require `code` |

## 0.10.0: one ordered `entries` tree

The public document no longer splits a scope into `open` and `events` lists.
Each scope is one `children` list in record order, every entry says what it is
with `kind`, and the root list is `entries`.

| 0.9 | 0.10.0 |
|---|---|
| root `open` + root `events` | root `entries`, each item `kind: "open"` or `kind: "event"` |
| `open[].open` + `open[].events` | `entries[].children` — events and nested opens interleaved as recorded |
| `open[].close` (object) | unchanged, `close` on the open; absent while unclosed |
| root `close` (orphans) | unchanged; the only place an `openId` still appears |
| `openId` on nested events / closes | removed — the nesting is the scope |
| `new LogJson($schemaUrl, $open, $events, $close, $links)` | `new LogJson($schemaUrl, $records, $closes, $links)` — `$records` is the flat list of opens and events, `$closes` the flat list of closes |
| `$log->open` / `$log->events` / `$log->close` / `toTreeArray()` | `$log->records` / `$log->closes`; read the tree from `toArray()` |
| `$log->open === []` emptiness check | `$log->records === []` |
| `OpenCloseEntry` carrying child opens | `OpenCloseEntry(id, type, schemaUrl, context, parentId)` — a flat record |
| `EventEntry` carrying nested closes | `EventEntry(id, type, schemaUrl, context, openId, profile)` — a flat record |
| envelope `required: ["$schema"]`, `open`/`events` properties | `required: ["$schema", "entries"]`; `entry` is `oneOf(openEntry, eventEntry)` keyed on `kind` |
| stree: missing `open` section throws | missing `entries` section throws |

## The exception hierarchy is gone

Removed classes — delete every `catch` and throwable expectation against them:

- `Koriym\SemanticLogger\Exception\NoLogSessionException`
- `Koriym\SemanticLogger\Exception\NoOpenOperationsException`
- `Koriym\SemanticLogger\Exception\InvalidOperationOrderException`
- `Koriym\SemanticLogger\Exception\UnclosedLogicException`
- `Koriym\SemanticLogger\Exception\LogicException`
- `Koriym\SemanticLogger\Exception\RuntimeException` (stree now has its own, see below)

`open()` / `event()` / `close()` / `flush()` / `toArray()` / `jsonSerialize()`
always succeed.

### Behavior correspondence table

| 1.x / 0.8 | 0.9 |
|---|---|
| `flush()` with no session → `NoLogSessionException` | valid empty log (`"entries": []`), always resets |
| `catch (NoLogSessionException)` to skip persisting an empty session | flush never fails — check emptiness yourself instead: `$log->records === []` |
| `flush()` with unclosed opens → `UnclosedLogicException` | live tree + `unclosed_at_flush` diagnostic carrying the unclosed ids, always resets |
| `close()` with empty stack → `NoOpenOperationsException` | `close_without_open` diagnostic; context discarded; session unaffected |
| `close()` with non-innermost id → `InvalidOperationOrderException` | `close_id_mismatch` diagnostic; the stack is never guess-mutated, so correct closes still work |
| context fails to serialize → exception at `flush()` | placeholder entry at the operation itself + `context_serialization_failed` diagnostic |

## The flush contract change

- A terminal `flush()` (end of request) **no longer needs `try/catch`** — it
  cannot fail.
- `flush()` always returns a log and always resets: a session never leaks into
  the next request, including empty and broken sessions.
- `toArray()` / `jsonSerialize()` are total, non-destructive snapshots; they do
  not reset.
- The envelope schema is relaxed accordingly (`required: ["$schema"]`, `open`
  has no minimum): an empty session is a valid, self-describing document.

## Diagnostics vocabulary

Two core-owned context types carry what used to be exceptions:

- `semantic_logger_error` — protocol and recording failures, with a `code` of
  `context_serialization_failed`, `close_without_open`, `close_id_mismatch`,
  or `unclosed_at_flush`.
- `semantic_logger_invalid_context` — a placeholder that preserves an entry's
  position in the tree when its context cannot be serialized. Children still
  attach to it; the loss radius of one bad context is that entry, never the
  session.

The `semantic_logger_*` namespace is core-owned. Application context types must
match `^[a-z_]+$` and stay out of it.

## Validation moved to the validator

The runtime no longer flags invalid `TYPE` / `SCHEMA_URL` values at all — the
log is data. `SemanticLogValidator` now owns those checks:

- entry types must match `^[a-z_]+$` outside the reserved namespace
- `schemaUrl` must be an absolute URI or `./schemas/<name>.json`
- diagnostic entries are validated against the schemas bundled with this
  package and listed with their own severity — they are not violations

CI gate:

```bash
vendor/bin/validate-semantic-log.php --fail-on-diagnostics var/log/semantic.json schemas/
# or in code:
$validator->validate($file, $schemaDir, failOnDiagnostics: true);
```

## Layer responsibility

Diagnostics cover **the logger's own recording operations** — nothing more.
Detecting that a cache server is down, a database is slow, or a disk is full
is the job of monitoring and alerting, not of this library. If an operation
matters to your domain (e.g. a cache store that was skipped), record it as
your own context type with your own schema; that is domain data, not a logger
diagnostic.

## Consumer wrapper guidance (e.g. BEAR.QueryRepository `SafeSemanticLogger`)

Guard removal decision table for decorators written against 1.x:

| Wrapper mechanism | 0.9 |
|---|---|
| `try/catch` around `open`/`event`/`close`/`flush` | Remove — the logger never throws |
| `broken` flag / session tombstone (`log_session_broken`) | Remove — sessions cannot break |
| empty-string sentinel open id | Remove — `open()` always returns a usable id (placeholder on failure) |
| depth tracking / `isTopLevel()` | Keep — caller-intent concern, orthogonal (core-side absorption is a 1.0 candidate) |
| `__serialize` / `__unserialize` boundary | Keep — DI container serialization concern, orthogonal |

## Renderer (stree) correspondence table

| 1.x / 0.8 | 0.9 |
|---|---|
| `(new TreeRenderer())->render($logData, $config)` | `(new TreeRenderer($config))->renderTree($logData)`, or `$log->render(new TreeRenderer($config))` |
| `Koriym\SemanticLogger\Exception\RuntimeException` from the parser | `Koriym\SemanticLogger\Stree\Exception\RuntimeException` |
| empty `open` section throws | event-only / empty logs parse into a synthetic forest root |
| `koriym/semantic-logger: ^0.8` | `^0.9` — `LogJson` and `LogRendererInterface` are the public contract |

Concrete example: BEAR.QueryRepository's demo scripts (e.g.
`demo/run-dependency.php`) migrate with the one-line renderer API change
above.
