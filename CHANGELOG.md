# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2025-08-03

### Added
- Initial release of koriym/semantic-logger
- Type-safe structured logging with JSON schema validation
- Hierarchical logging with open/event/close patterns
- **OpenId correlation** for complete request-response traceability
- AbstractContext base class for type-safe context objects
- SemanticLogger main logger implementation
- LogJson immutable structured log output
- EventEntry and OpenCloseEntry for log structure
- Comprehensive test suite with 100% coverage
- Schema relations support for system transparency
- Flush pattern for one-time log consumption
- Complete documentation and examples

### Features
- PHP 8.1+ support with full type safety
- Zero configuration - just extend AbstractContext
- JSON Schema validation ready
- Structured output compatible with observability tools
- LIFO (Last-In-First-Out) operation order validation
- Unclosed operation detection and error handling

