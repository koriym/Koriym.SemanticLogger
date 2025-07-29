# Semantic Logger JSON Schema

## Overview

JSON Schema for validating semantic log entries with RFC 8288 compliant link relations.

## Schema File

### `semantic-log.json`

Complete semantic log format with hierarchical logging and RFC 8288 link relations support.

**Schema URL**: `https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json`

## Features

- **RFC 8288 Compliance**: Link relations follow IANA registered relation types
- **Extension Support**: Custom relation types via URI format  
- **Type Safety**: Full validation of open/event/close hierarchical structures
- **Debugging Context**: Relations provide source code, schema, and monitoring links

## Structure

```json
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "open": {
    "id": "process_1",
    "type": "process",
    "$schema": "https://example.com/schemas/process.json",
    "context": { "name": "data processing" }
  },
  "events": [
    {
      "id": "event_1", 
      "type": "event",
      "$schema": "https://example.com/schemas/event.json",
      "context": { "message": "processing started" },
      "openId": "process_1"
    }
  ],
  "close": {
    "id": "result_1",
    "type": "result", 
    "$schema": "https://example.com/schemas/result.json",
    "context": { "status": "success" },
    "openId": "process_1"
  },
  "relations": [
    {
      "rel": "related",
      "href": "https://github.com/example/my-app",
      "title": "Source Code Repository"
    },
    {
      "rel": "describedby",
      "href": "https://example.com/db/schema/processes.sql",
      "title": "Database Schema"
    }
  ]
}
```

## Link Relations (RFC 8288)

Supported IANA registered relation types:

- `related` - Related resources (source code, repositories)
- `describedby` - Schemas, documentation that describe the resource
- `help` - Help documentation  
- `monitor` - Monitoring and metrics
- `self` - Self-reference
- And all other [IANA Link Relations](https://www.iana.org/assignments/link-relations/link-relations.xhtml)

Extension relations must be URIs:
```json
{
  "rel": "https://example.com/rels/xhprof-profile",
  "href": "https://xhprof.example.com/run/abc123"
}
```

## Validation

```bash
# Using ajv-cli
npm install -g ajv-cli
ajv validate -s semantic-log.json -d your-log.json
```
