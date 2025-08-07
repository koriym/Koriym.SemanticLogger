# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - 2025-08-07

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

