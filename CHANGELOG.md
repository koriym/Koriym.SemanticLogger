# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

