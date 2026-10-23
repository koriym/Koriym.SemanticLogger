# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.11.0] - 2026-10-23

### Added
- **Record limit.** A session records at most 1000 opens, events and rejected closes (`new SemanticLogger($maxRecords)` to change); later ones are dropped, while closes of recorded opens are still written. `flush()` then appends one `semantic_logger_truncated` point `{limit, dropped, firstDroppedType}`, which the validator reports as a warning, not a diagnostic.

### Changed
- **BREAKING — the document is `log`.** Every list is `log` (was `entries`); `kind` and the root `close[]` array are gone. A record's kind follows from its keys: `close` = completed, `log` without `close` = interrupted, neither = point, a root record with `openId` = orphan close. See [MIGRATION.md](MIGRATION.md).
- **BREAKING — `schemaUrl` on every record.** The writer never emits a `types` index. Readers still accept one; the validator rejects a `schemaUrl` that disagrees with it.
- **BREAKING — closes are records.** `new LogJson($schemaUrl, $records, $links = [])`: `$closes` is gone and a close is a `CloseEntry` in `$records`. `OpenCloseEntry` is renamed `OpenEntry`; `EventEntry` drops `profile`.
- **BREAKING — diagnostic `kind` is `code`** in `semantic_logger_error` and `semantic_logger_invalid_context.errors[]`.
- **BREAKING — validator.** Paths read `log[i]` / `.close`. New errors: a record without a schema, a duplicate `id`, a double close. An interrupted interval is a warning.
- **BREAKING (stree)** — `LogDataParser` reads `log`; a document without it throws `Invalid log data: missing log section`.

### Fixed
- A context with private or protected properties keeps its public properties instead of becoming a `semantic_logger_invalid_context` placeholder. A context with no public properties is recorded as `{}`.
- `DevSemanticLogger` no longer advances its profiling stack or finalizes timing when `close()` targets a tracked operation that is not the innermost open.
- `stree --json` no longer rewrites empty JSON objects as `[]` (e.g. `"context": {}`); the passthrough keeps objects and arrays apart.
- `stree` (tree mode) reports a log whose root is not a JSON object (`123`, `"x"`, `[...]`) as `Error: Invalid log data in <file>: expected a JSON object` with exit 1. A scalar root used to crash with an uncaught `TypeError` (exit 255); a list root used to fail later with `missing log section`. `--json` still passes any valid JSON through.

## [0.10.0] - 2026-10-20

### Changed
- **BREAKING — one ordered `entries` tree.** Root `open` / `events` become `entries`, and each open's lists become one `children` list in record order; every entry carries `kind`. See [MIGRATION.md](MIGRATION.md).
- **BREAKING — flat records in `LogJson`.** `new LogJson($schemaUrl, $records, $closes = [], $links = [])` with flat `OpenCloseEntry` / `EventEntry` values; `LogTreeBuilder` builds the tree.
- **BREAKING (stree)** — `LogDataParser` reads `entries` / `children` / `kind`; a document without `entries` throws `RuntimeException('Invalid log data: missing entries section')`.
- stree's compact close line leads with the close type (`└── cache_hit layer=resource`); `--full` labels it `close <type>`.
- **BREAKING (stree)** — `TreeNode::getCloseSignals()` is renamed `getCloseLine()`.

### Fixed
- Whole-number floats (`0.0`, `2250.0`) stay floats when a context is frozen (#44).
- stree truncates long values by character, not byte, so multibyte text is no longer cut into invalid UTF-8, and shows how much was cut as `...(+N)` (#52).
- stree's `+N items` overflow for arrays also counts characters, not bytes (#52).

### Removed
- `LogJson::toTreeArray()`, `LogJson::$open` / `$events` / `$close`, and the `OpenChildrenByParent` / `EventsByOpenIdMap` / `LogTree` type aliases.

## [0.9.0] - 2026-08-12

### Added
- **`LogRendererInterface`** and **`LogJson::render()`** — render a log in any format by implementing one interface.
- **Logger-recorded diagnostics.** Failures are recorded as `semantic_logger_error` and `semantic_logger_invalid_context` entries instead of thrown, with bundled schemas.
- **Validator metadata checks.** `type` must match `^[a-z_]+$` outside `semantic_logger_*`; `schemaUrl` must be absolute or `./schemas/<name>.json`.
- **`--fail-on-diagnostics`** turns logger-recorded diagnostics into a failing CI gate.

### Changed
- **BREAKING — the logger never throws.** Misuse and recording failures become diagnostic entries. See [MIGRATION.md](MIGRATION.md).
- **BREAKING — flush contract.** `flush()` on an empty session returns an empty log, on unclosed opens the live tree plus `unclosed_at_flush`; it always resets.
- **BREAKING — envelope schema relaxed.** Only `$schema` is required, so an empty session is valid.
- Contexts are deep-frozen at record time; a context that fails to serialize loses only its own entry.
- `NullSemanticLogger` returns `noop_N` ids and resets them on `flush()`.
- **BREAKING — `TreeRenderer` API.** `render(array $logData, RenderConfig $config)` becomes `(new TreeRenderer($config))->renderTree($logData)`; `render(LogJson $log)` implements `LogRendererInterface`.
- The tree renderer is a subpackage under `src-stree/` (`koriym/stree`); the namespace `Koriym\SemanticLogger\Stree` is unchanged.
- stree accepts event-only and empty logs; parser errors throw `Koriym\SemanticLogger\Stree\Exception\RuntimeException`.

### Fixed
- stree no longer prints the ISO `timestamp` on every node line (#39).
- A `JsonSerializable` context whose `jsonSerialize()` returns an object records that object, not the raw properties.

### Removed
- **BREAKING — the exception hierarchy is gone** (`NoLogSessionException`, `NoOpenOperationsException`, `InvalidOperationOrderException`, `UnclosedLogicException`, `LogicException`, `RuntimeException`). Remove the `catch` blocks; see [MIGRATION.md](MIGRATION.md).

## [0.7.0] - 2026-04-22

### Changed
- **BREAKING — profiling payload.** `profile` is omitted when nothing was captured; otherwise it carries `xdebugTrace` / `xhprofProfile` lists of `{path}` (was `xdebug` / `xhprof`).
- stree shows operation targets and close results in compact mode, and input, injections, close payload and profile in `--full`.

### Fixed
- `DevSemanticLogger` now skips Xdebug trace capture cleanly when Xdebug is unavailable or `trace` mode is not enabled, avoiding noisy runtime notices.
- Xdebug trace capture now falls back to a writable temp directory on macOS when the configured output directory would prevent trace startup.

## [0.6.0] - 2026-04-21

### Added
- `LogJson::toTreeArray()` emits one nested tree for development logs.
- `Stree/LogDataParser` accepts both the legacy split `open`/`close` JSON and the new tree-shaped development log format.

### Changed
- **BREAKING — DevLogger output shape.** `DevLogger` writes the nested tree (`toTreeArray()`) instead of separate `open` and `close` trees.

### Fixed
- Tree conversion now preserves orphaned events whose `openId` does not match any `open` by keeping them at the top-level `events` bucket instead of dropping them.

### Removed
- **MCP server integration** (server, CLI entrypoint, setup guides). MCP integration now lives outside this package.

## [0.5.0] - 2026-04-21

### Fixed
- **#24 — sibling open/close pairs were nested.** The parent is now captured at `open()` time instead of being reconstructed from close order.

### Changed
- **BREAKING — `open` and `close` are lists at every level**, in JSON and in `LogJson` / `OpenCloseEntry` / `EventEntry`; `OpenCloseEntry` gains `parentId`.

### Closes

- #24 (sibling open/close pairs incorrectly nested)

## [0.4.0] - 2026-04-20

### Added
- **Generic `SignalExtractor`** — any context renders as one line of up to 4 meaningful scalars.
- **`NodeFormatterInterface` + `FormatterRegistry`** — register a formatter per context type.
- **`⎿` close line** — a node's close is shown under its events and nested opens, with only the signals that changed.
- **`--values` / `-V` flag** on `bin/stree` — opt-in signal that registered formatters may consult to render values instead of only keys.
- `JsonSerializable` contexts are recorded through `jsonSerialize()`.
- **PHP 8.5** added to the CI build matrix.

### Changed
- Close signals drop the leading `→ `; `⎿` marks the close.
- The session root is rendered flush-left rather than under a synthetic `session` header.
- Status annotation (`: Failed`, `: unclosed`) propagates upward through the tree.
- Demo JSON files validate against `demo/schemas/`.

### Removed
- **BREAKING**: `src/Stree/HtmlRenderer.php` and the `--format text|html`, `--depth`, `--expand` CLI flags. The text tree renderer (plus `--json` passthrough) is the sole output path.
- **BREAKING**: compact output has no `session` root and shows close signals on a `⎿` line. Parsers of the old text format must adapt.

### Closes

- #15 (Redesign stree: generic JSON → tree rendering inspired by treelog)
- #19 (demo files failing context-schema validation)
- #22 (Extract Be Framework-specific formatters from stree; introduce generic formatter registry)

## [0.3.1] - 2026-04-17

### Fixed
- **bin scripts**: Fix autoload resolution when installed as a Composer dependency via `vendor/bin/`

## [0.3.0] - 2026-04-17

### Added
- **Semantic Tree Visualizer (stree)**: CLI tool for visualizing semantic log trees with text and HTML output formats, configurable depth limits, time thresholds, and type-based expansion
- **OpenCloseEntry**: New entry type with `close` field serialization for paired open/close operations
- **PHP 8.5 support**: CI now runs tests across PHP 8.2, 8.3, 8.4, and 8.5

### Changed
- **Types.php**: relation types renamed to link types (RFC 8288).

### Fixed
- **MCP server JSON encoding**: Handle `json_encode` failures gracefully instead of silently emitting empty responses
- **XdebugTrace**: Correctly handle null return values from trace operations
- **Demo validation**: Resolved demo output validation and test failures introduced during stree development

## [0.2.1] - 2025-08-07

### Added
- **`schemaUrl` field** on each entry, pointing to the context's schema.
- **Custom Semantic Log Validator**: `SemanticLogValidator` with detailed error reporting
- **MCP Server Profile Management**: `/profile list` command for available semantic logs
- **Profiler Classes**: XdebugTrace, PhpProfile, XHProfResult for performance profiling
- **Schema URL Specification**: Documentation explaining non-standard but practical approach
- **CLI Tools**: `semantic-mcp` and `validate-semantic-log.php` as composer bin executables

### Changed
- **SchemaUrl over $schema**: Replace JSON Schema standard with AI-friendly approach
- **MCP Server Independence**: Remove php-dev.ini dependency, use inline PHP options
- **Graceful Extension Handling**: Continue operation when Xdebug/XHProf unavailable
- **Demo Output**: Rename `semantic-log.json` to `demo.json` for clarity

### Fixed
- **DevLogger Flush Issue**: Prevent duplicate flush() calls corrupting logger state
- **Extension Loading**: Smart detection and conditional loading of profiling extensions
- **Dynamic Directories**: Remove hardcoded /tmp paths, use configurable directories

## [0.2.0] - 2025-08-07

### Added
- Universal Semantic Logger Schema with hierarchical recursion support
- MCP Server Integration for AI-powered log analysis
- Comprehensive demo suite with `composer demo` command
- Context examples and JSON schemas for validation

### Changed
- Enhanced semantic-log.json schema with recursive `$ref` patterns
- Fixed JSON Schema compliance

## [0.1.0] - 2025-08-03

### Added
- Initial release of koriym/semantic-logger
- Type-safe structured logging with hierarchical open/event/close patterns
- OpenId correlation for request-response traceability
- AbstractContext base class and SemanticLogger implementation
- JSON Schema validation support
- Comprehensive test suite with full coverage
