# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Testing
- `composer test` - Run PHPUnit tests
- `composer tests` - Run full test suite (code style, tests, static analysis)
- `composer coverage` - Generate test coverage reports (requires Xdebug)
- `composer pcov` - Generate coverage using PCOV extension
- `composer phpdbg` - Generate coverage using phpdbg
- `./vendor/bin/phpunit` - Run tests directly with PHPUnit
- `./vendor/bin/phpunit --filter MethodName` - Run specific test method
- `./vendor/bin/phpunit tests/SpecificTest.php` - Run specific test file

### Code Quality
- `composer cs` - Check code style using PHP_CodeSniffer
- `composer cs-fix` - Fix code style violations automatically
- `composer sa` - Run static analysis (Psalm + PHPStan)
- `composer phpstan` - Run PHPStan analysis only
- `composer psalm` - Run Psalm analysis only
- `composer phpmd` - Run PHP Mess Detector

### Code Style Guidelines
- **Avoid else statements**: Use early returns instead of else blocks for better readability
- **Prefer guard clauses**: Check conditions early and return/throw immediately

### Commit Message Guidelines
This project does NOT use conventional commits prefixes like `feat:`, `fix:`, `docs:`, etc.

**Use simple, descriptive commit messages:**
- ✅ `Add semantic log validator`
- ✅ `Update README with validation examples`
- ✅ `Fix XHProf result class implementation`
- ❌ `feat: add semantic log validator`
- ❌ `docs: update README with validation examples`
- ❌ `fix: correct XHProf result class implementation`

Keep commit messages concise and focused on what was changed, not categorizing the type of change.

### Utility
- `composer clean` - Clear caches (PHPStan, Psalm)
- `composer build` - Complete build process (style, analysis, coverage, metrics)
- `composer baseline` - Generate baseline for PHPStan and Psalm
- `composer metrics` - Generate metrics report
- `composer crc` - Run composer require checker

### Demo and Schema Tooling
- `composer demo` - Run `demo/run.php` with Xdebug+XHProf profiling, then validate and render the resulting log
- `composer validate-demo` - Validate bundled demo JSON logs (`demo/*.json`) against `demo/schemas`
- `composer sgen` - Regenerate `demo/schemas/combined.json` from per-context schemas via `demo/generate-schema.php`
- `composer stree` / `composer stree:full` / `composer stree:perf` / `composer stree:db` - Render `demo/semantic-log-demo.json` as a tree with different filters

## Architecture Overview

koriym/semantic-logger is a type-safe structured logging library with JSON schema validation for hierarchical application workflows.

### Core Components

**SemanticLogger** (`src/SemanticLogger.php`)
- Main logger interface implementing hierarchical logging
- Manages open/event/close operation patterns
- Uses SplStack for nested operation tracking
- Implements JsonSerializable for direct output

**AbstractContext** (`src/AbstractContext.php`)
- Base class for all context objects
- Enforces type safety with const TYPE and SCHEMA_URL
- Context data extracted via array casting

**EventEntry / OpenCloseEntry** (`src/EventEntry.php`, `src/OpenCloseEntry.php`)
- Immutable value objects for log entries
- `EventEntry` represents events and matched `close` nodes (including nested close children and attached profile data)
- `OpenCloseEntry` represents `open` operations and carries its child opens plus the `parentId` captured at open time

**LogJson** (`src/LogJson.php`)
- Immutable structured log output
- Implements JsonSerializable; public tree shape nests child opens, scoped events, and matching `close` under each `open`
- Top-level `events` / `close` arrays are reserved for root-scope or orphan diagnostics

**DevLogger / DevSemanticLogger** (`src/DevLogger.php`, `src/DevSemanticLogger.php`)
- Development-time helpers that flush the logger and write `semantic-dev-*.json` to disk
- `DevLogger` takes an existing `SemanticLoggerInterface` and writes its `flush()` output; `DevSemanticLogger` combines logging + disk sink

**SemanticLogValidator / SemanticLogValidatorInterface** (`src/SemanticLogValidator.php`, `src/SemanticLogValidatorInterface.php`)
- Validates a semantic-log JSON file against the envelope schema and each referenced context schema
- Backs `bin/validate-semantic-log.php`
- Schemas bundled in `docs/schemas/` are shipped in the composer dist and used as the default fallback

**DynamicSchemaGenerator** (`src/DynamicSchemaGenerator.php`)
- Builds a combined JSON schema document from per-context schemas (see `demo/generate-schema.php`, `composer sgen`)

**Stree command** (`src-stree/` monorepo subpackage: `src-stree/src/`, `src-stree/bin/stree`, `src-stree/tests/`)
- Tree-shaped renderer for semantic logs; `StreeCommand` is the entry point
- Lives as its own composer package (`koriym/stree`, namespace `Koriym\SemanticLogger\Stree`) under `src-stree/`, merged into the root autoload; `subtree split`-able to a standalone Packagist package later
- `TreeRenderer` implements the core `Koriym\SemanticLogger\LogRendererInterface`, so `$log->render(new TreeRenderer())` works via double dispatch
- `FormatterRegistry` / `NodeFormatterInterface` let context types register custom value formatting
- `SignalExtractor`, `RenderConfig`, `TreeRenderer`, `TreeNode`, `LogDataParser` handle parsing and rendering

**Profiler value objects** (`src/Profiler/`)
- `OperationProfile` aggregates per-operation profiler artifacts (wallTime + XHProf / Xdebug segments)
- `PhpProfile`, `XdebugTrace`, `XHProfResult` are immutable value objects for individual artifacts

**Types.php** (`src/Types.php`)
- Central catalog of `@psalm-type` / `@phpstan-type` aliases (ContextData, EventEntryList, OpenCloseEntryList, SchemaLinks, OperationProfileData, etc.)
- Not instantiable; import with `@psalm-import-type` / `@phpstan-import-type`. See `skills/php-domain-types/SKILL.md` for the policy.

### Key Architectural Patterns

**Hierarchical Logging Pattern**
- `open()` - Start new operation context (pushes to stack)
- `event()` - Log events within current operation
- `close()` - End operation with result/status (pops from stack)
- `flush()` - Get complete log and reset state

**Type Safety Through Constants**
- Each context class defines `const TYPE` and `const SCHEMA_URL`
- Static analysis ensures correct usage patterns
- Runtime type checking via const access

**Schema Validation Ready**
- JSON schema URLs defined in context constants
- Compatible with external validation tools
- Structured output follows defined schema format

**Flush Pattern**
- One-time log consumption with state reset
- Prevents log pollution between operations
- Returns immutable LogJson object

**Tree-shaped Public JSON**
- `flush()` returns a tree: each `open` node carries its own nested child opens, scoped events, and its matching `close`
- Normal results are read from paths like `$log['open'][0]['close']`, not a separate top-level close list
- Top-level `events` / `close` arrays exist only for root-scope or orphan diagnostics; consumers should treat the tree as the source of truth

**Profiler Integration Pattern**
- Profiler output is modeled as immutable value objects under `src/Profiler/` (`OperationProfile`, `XdebugTrace`, `XHProfResult`, `PhpProfile`)
- `docs/profiler-di-implementation.md` still describes an older `ProfilerInterface` / `ProfileResult` DI shape for BEAR.Resource; those top-level classes no longer exist in `src/`, so treat that doc as historical context, not current API

### Testing Structure

**Test Organization**
- Unit tests in `tests/` directory
- Fake implementations in `tests/Fake/`
- Schema validation tests for JSON output

**Test Patterns**
- Context object testing with type safety
- Hierarchical logging flow testing
- JSON output validation

### JSON Schema Integration

**Schema Structure**
- Each context maps to specific JSON schema URL
- Schemas define validation rules for context properties
- Compatible with JSON Schema Draft 2020-12

**Validation Workflow**
- Context objects define schema URLs as constants
- Output includes schema references for validation
- External tools can validate against schemas

## Development Guidelines

### Context Implementation
- Extend AbstractContext for all context classes
- Define TYPE and SCHEMA_URL constants
- Use readonly properties for immutability
- Keep context data focused and specific

### Type Safety
- Import types from Types.php using `@psalm-import-type`
- Use typed parameters and return types
- Leverage static analysis tools
- For generic arrays, prefer `array<T>`; use `array<string, T>` or `list<T>` when key shape matters, and do not use `array<mixed, mixed>`

### Logging Patterns
- Always call flush() to get complete log output
- Use try/finally blocks to ensure close() is called
- Nest operations logically with open/close pairs

### Schema Design
- Create specific schemas for each context type
- Use JSON Schema validation constraints
- Maintain schema versioning for breaking changes

### Error Handling
- Use appropriate exception classes from `src/Exception/`
- Handle stack underflow in close operations
- Validate schema URLs format

### Profiler Integration (BEAR.Resource)
- Profile data is carried by the immutable value objects in `src/Profiler/` (`OperationProfile` + artifact classes)
- `docs/profiler-di-implementation.md` predates the current layout and still references a removed `ProfilerInterface` / `ProfileResult`; keep it as historical context only

## Static Analysis Configuration

- **Psalm**: `psalm.xml` - Type checking with baseline support
- **PHPStan**: `phpstan.neon` - Code analysis with baseline
- **PHP_CodeSniffer**: `phpcs.xml` - PSR-12 compliance
- **PHPMD**: `phpmd.xml` - Mess detection rules
