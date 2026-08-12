# Migration Guide: 0.8 / 1.x → 0.9

0.9 rebuilds the logger around a single behavior: **the logger never throws**.
Failures and protocol misuse become core-owned diagnostic entries in the log
itself, and quality gates move to the reading side (the validator and CI).

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
| `flush()` with no session → `NoLogSessionException` | valid empty log (`"open": []`), always resets |
| `catch (NoLogSessionException)` to skip persisting an empty session | flush never fails — check emptiness yourself instead: `$log->open === []` (top-level `events`/`close` may still exist, e.g. diagnostics) |
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

- `semantic_logger_error` — protocol and recording failures, with a `kind` of
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
