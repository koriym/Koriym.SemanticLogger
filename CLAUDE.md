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

**LogEntry/OpenEntry** (`src/LogEntry.php`, `src/OpenEntry.php`)
- Immutable value objects for log entries
- LogEntry for events and close operations
- OpenEntry for hierarchical open operations with nesting support

**LogJson** (`src/LogJson.php`)
- Immutable structured log output
- Implements JsonSerializable for clean JSON export
- Contains the complete log session data

**ProfilerInterface** (`src/ProfilerInterface.php`)
- Interface for profiling operations (XHProf, Xdebug)
- Used by BEAR.Resource for performance profiling
- Designed for dependency injection

**ProfileResult** (`src/ProfileResult.php`)
- Immutable value object for profiling results
- Contains optional XHProf and Xdebug trace file paths
- Used by ProfilerInterface implementations

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

**Profiler Integration Pattern**
- ProfilerInterface provides abstraction for performance profiling
- ProfileResult encapsulates profiling output (XHProf files, Xdebug traces)
- Designed for BEAR.Resource DI integration to eliminate duplicate profiling code

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
- ProfilerInterface defines contract for profiling operations
- Implement VerboseProfiler in BEAR.Resource to handle XHProf/Xdebug
- Use dependency injection to eliminate duplicate profiling code
- See `docs/profiler-di-implementation.md` for BEAR.Resource implementation guide

## Static Analysis Configuration

- **Psalm**: `psalm.xml` - Type checking with baseline support
- **PHPStan**: `phpstan.neon` - Code analysis with baseline
- **PHP_CodeSniffer**: `phpcs.xml` - PSR-12 compliance
- **PHPMD**: `phpmd.xml` - Mess detection rules