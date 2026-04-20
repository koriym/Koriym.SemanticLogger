# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.5.0] - 2026-04-21

### Fixed
- **#24 — sibling open/close pairs were falsely nested.** `buildNestedOpen()` reconstructed the hierarchy from completion order alone, so sequential `open/close` pairs at the same level had `open_2` wrapping `open_1` whenever they were siblings. `SemanticLogger` now captures the real parent at `open()` time via the open stack, and completed operations carry their own `parentId` — the flush step groups by `parentId` instead of reconstructing from close order.

### Changed
- **BREAKING — on-wire JSON shape.** `open` and `close` are now arrays of entries at every level (top-level and nested). `OpenCloseEntry.open` is `list<OpenCloseEntry>`; `EventEntry.close` is `list<EventEntry>`; `LogJson.open` / `LogJson.close` are top-level lists (multiple roots allowed). `OpenCloseEntry` gained a `parentId: string|null` field.
- **BREAKING — PHP API.** `LogJson::$open` / `LogJson::$close` are lists; `OpenCloseEntry::$open` is a list; `EventEntry::$close` is a list. `EventEntry::withClose()` takes a list.
- `DevSemanticLogger` walks the new list shape when attaching profiles (`attachProfilesToCloses` replaces `attachProfilesToCloseChain`).
- `Stree/LogDataParser` walks the new list shape; the single-root constraint is enforced at render time (multi-root logs throw a clear error for `stree`).
- Schema (`docs/schemas/semantic-log.json`) factored into `openEntry` / `closeEntry` definitions and updated to array-at-every-level.

### Closes

- #24 (sibling open/close pairs incorrectly nested)

## [0.4.0] - 2026-04-20

### Added
- **Generic `SignalExtractor`** (`src/Stree/SignalExtractor.php`) — domain-agnostic 1-line signal extraction: picks up to 4 meaningful scalars per open context, shortens FQCNs to the basename, truncates long strings at 40 chars, partial-overflow arrays render as `[a, b +N items]`, and timing / id / schemaUrl keys are excluded. Unknown-type contexts now render with useful signals instead of collapsing to a bare type label.
- **`NodeFormatterInterface` + `FormatterRegistry`** — per-type formatter extension point. `TreeNode::getDisplayLine()` consults `$config->formatters?->get($type)` before the generic fallback, so domain vocabularies (e.g. the Be Framework `becoming_*` family) can supply their own renderer without this repo needing to know about them. A formatter may return a multi-line string; `TreeRenderer` splits on `\n` and places the continuation under the open line with matching indentation.
- **`⎿` close-line** in `TreeRenderer` — open / event / close structure is visible at a glance: the node's open line, then events and nested opens as `├──` / `└──` children, then a `⎿` continuation with signals that differ from open.
- **`--values` / `-V` flag** on `bin/stree` — opt-in signal that registered formatters may consult to render values instead of only keys.
- **`SemanticLogger::contextToArray()`** now respects `JsonSerializable`, so contexts can emit editorial shapes (e.g. wrap empty maps in `stdClass` so they serialize as `{}` rather than `[]`).
- **PHP 8.5** added to the CI build matrix.

### Changed
- `TreeNode::extractContextInfo()` no longer dispatches through hardcoded `match` arms; it defers to `SignalExtractor` (or a registered formatter) uniformly.
- Close-diff signals dropped the leading `→ ` prefix — the renderer now supplies `⎿` as the close marker. The inline `old→new` arrow in changed values is preserved.
- The session root is rendered flush-left rather than under a synthetic `session` header.
- Status annotation (`: Failed`, `: unclosed`) propagates upward through the tree.
- Demo JSON files (`demo/simple-demo.json`, `demo/complex-demo.json`) now validate cleanly against `demo/schemas/`.

### Removed
- **BREAKING**: `src/Stree/HtmlRenderer.php` and the `--format text|html`, `--depth`, `--expand` CLI flags. The text tree renderer (plus `--json` passthrough) is the sole output path.
- **BREAKING**: the compact output shape changed (no `session` root, close signals on a `⎿` line instead of inline `→ …` on the open line). Consumers that parsed the old text format must re-adapt.

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
- **Static analysis baselines cleared**: Both `psalm-baseline.xml` and `phpstan-baseline.neon` are now empty; all underlying type issues have been fixed rather than suppressed
- **Type safety improvements**: Added scalar/numeric guards and helper methods across the Stree package and `SemanticProfilerMcpServer` to replace unsafe `(string) mixed` casts
- **PHPMD 3.x migration**: Updated to the new Symfony Console subcommand syntax (`phpmd analyze src --format=text --ruleset=./phpmd.xml`)
- **Types.php**: Refactored relation types to link types for RFC 8288 compliance
- **Code style**: Eliminated remaining `else` expressions in favor of early returns
- **AbstractContext**: Added comprehensive `@var` type annotations for constants

### Fixed
- **MCP server JSON encoding**: Handle `json_encode` failures gracefully instead of silently emitting empty responses
- **XdebugTrace**: Correctly handle null return values from trace operations
- **Demo validation**: Resolved demo output validation and test failures introduced during stree development

## [0.2.1] - 2025-08-07

### Added
- **AI-Human Understanding Bridge**: SchemaUrl field bridges AI-human comprehension gap
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
- **Code Style**: Remove else statements following project guidelines

### Fixed
- **DevLogger Flush Issue**: Prevent duplicate flush() calls corrupting logger state
- **Schema Validation**: 26 semantic log files validate successfully with zero errors
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

