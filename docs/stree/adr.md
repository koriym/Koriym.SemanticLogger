# ADR: stree - Semantic Tree Visualizer Architecture

## Status

Implemented

## Context

SemanticLogger produces hierarchical JSON logs with nested operations and events. These logs are structured but difficult to read and analyze manually, especially for:

1. **Debugging complex workflows** - Understanding the flow of nested operations
2. **Performance analysis** - Identifying slow operations in the hierarchy  
3. **Visual inspection** - Seeing the overall structure at a glance
4. **Progressive disclosure** - Starting with high-level view and drilling down as needed

## Decision

We decided to implement `stree` (semantic tree), a command-line tool that renders SemanticLogger JSON output as ASCII tree structures similar to the Unix `tree` command.

### Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   StreeCommand  │───▶│  LogDataParser  │───▶│    TreeNode     │
│                 │    │                 │    │                 │
│ - CLI interface │    │ - JSON parsing  │    │ - Tree structure│
│ - Option parsing│    │ - Event linking │    │ - Context info  │
│ - Error handling│    │ - Time extraction│   │ - Display logic │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │
         ▼
┌─────────────────┐    ┌─────────────────┐
│  TreeRenderer   │───▶│  RenderConfig   │
│                 │    │                 │
│ - ASCII output  │    │ - Depth limits  │
│ - Tree symbols  │    │ - Expand rules  │
│ - Depth control │    │ - Time threshold│
└─────────────────┘    └─────────────────┘
```

### Core Design Decisions

#### 1. **Default Depth Limiting (2 levels)**
- **Rationale**: Prevents information overload for complex hierarchies
- **Implementation**: Configurable via `--depth=N` option
- **Fallback**: `[...]` indicator shows when content is truncated

#### 2. **Selective Expansion**
- **Rationale**: Allow deep inspection of specific operation types
- **Implementation**: `--expand=ContextType` bypasses depth limits for specified types
- **Use case**: `--expand=DatabaseQuery` to see all database operations regardless of depth

#### 3. **Performance Filtering**
- **Rationale**: Focus on slow operations for performance analysis
- **Implementation**: `--threshold=Xms` hides operations faster than threshold
- **Flexibility**: Supports `ms`, `s` units and decimal values

#### 4. **Context-Aware Display**
- **Rationale**: Show meaningful information based on operation type
- **Implementation**: Each context type has specialized display logic
- **Examples**:
  - HTTP requests: `POST /api/users`
  - Database queries: `SELECT users`
  - Cache operations: `get user_123 (HIT)`

#### 5. **Execution Time Prominence**
- **Rationale**: Time information is critical for performance analysis
- **Implementation**: Always displayed in brackets with appropriate units
- **Format**: `[500.0μs]`, `[25.0ms]`, `[1.5s]`

### Data Flow

1. **Input**: SemanticLogger JSON file
2. **Parse**: Extract hierarchical `open` structure and flat `events` array
3. **Transform**: Build tree structure with linked events
4. **Filter**: Apply depth, expansion, and time threshold rules
5. **Render**: Generate ASCII tree with context-specific formatting
6. **Output**: Display to console

### JSON Structure Handling

The tool handles SemanticLogger's dual structure:

- **Hierarchical**: Nested `open.open.open...` structure for operations
- **Flat Events**: Array of events linked via `openId` references
- **Timing Data**: Multiple possible fields (`executionTime`, `responseTime`, `duration`)

## Consequences

### Positive

1. **Immediate Visual Understanding**: Complex logs become instantly readable
2. **Progressive Disclosure**: Start simple, drill down as needed
3. **Performance Focus**: Threshold filtering highlights bottlenecks
4. **Developer Friendly**: Unix `tree`-like interface feels familiar
5. **Extensible**: Context display logic easily extended for new types

### Negative

1. **Terminal Dependency**: Requires terminal/console environment
2. **ASCII Limitations**: No color, limited formatting options
3. **Memory Usage**: Loads entire JSON into memory (acceptable for log files)

### Neutral

1. **Single-purpose Tool**: Focused specifically on tree visualization
2. **Read-only**: Does not modify or generate new log data

## Alternatives Considered

### 1. Web-based Visualizer
- **Pros**: Rich UI, interactive expansion, syntax highlighting
- **Cons**: Requires server/browser, more complex deployment
- **Decision**: CLI tool better fits developer workflow

### 2. JSON Pretty-printer Enhancement
- **Pros**: Simpler implementation, reuses existing tools
- **Cons**: Still requires manual parsing, less intuitive
- **Decision**: Custom tree format much more readable

### 3. Integration with existing tools (jq, etc.)
- **Pros**: Leverages existing ecosystem
- **Cons**: Complex query syntax, poor hierarchical display
- **Decision**: Domain-specific tool provides better UX

## Implementation Notes

- **No Dependencies**: Uses only PHP standard library for portability
- **Error Handling**: Graceful handling of malformed JSON, missing files
- **Testing**: Comprehensive unit tests for all components
- **CLI Standards**: Follows Unix CLI conventions for options and help

## Future Considerations

1. **Color Support**: Terminal color for better visual distinction
2. **Interactive Mode**: Navigate tree with keyboard
3. **Export Formats**: SVG, HTML output for documentation
4. **Real-time Mode**: Watch log files for live updates