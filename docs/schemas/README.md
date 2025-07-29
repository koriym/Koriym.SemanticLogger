# Semantic Logger Schemas

## Overview

Semantic Logger uses JSON Schema to define the structure and meaning of log data. This enables:

- **Complete Type Safety**: All log data is validated against schemas
- **Semantic Meaning**: AI and tools understand what each field means
- **Documentation**: Schemas serve as living documentation
- **Validation**: Automatic validation of log structure

## Core Schema

### `structured-log.json`

The main schema that defines the overall structure of semantic logs.

**Key Components:**

- **stream**: Array of log sessions (stories)
- **open**: Beginning of a story with typed context
- **events**: Array of events that occurred (plot points)
- **close**: End of the story with typed results

**Schema URL**: `https://koriym.github.io/semantic-logger/schemas/structured-log.json`

## Schema Structure

```json
{
  "$schema": "https://koriym.github.io/semantic-logger/schemas/structured-log.json",
  "title": "My Application Log",
  "stream": [
    {
      "open": {
        "type": "user_registration",
        "$schema": "https://myapp.com/schemas/user_registration.json",
        "context": {
          "email": "user@example.com",
          "source": "web"
        }
      },
      "events": [
        {
          "type": "email_validation",
          "$schema": "https://myapp.com/schemas/email_validation.json", 
          "context": {
            "email": "user@example.com",
            "valid": true,
            "check_duration_ms": 45
          }
        }
      ],
      "close": {
        "type": "registration_success",
        "$schema": "https://myapp.com/schemas/registration_success.json",
        "context": {
          "user_id": "usr_12345",
          "welcome_email_sent": true
        }
      }
    }
  ]
}
```

## Domain-Specific Schemas

Each `type` should have a corresponding JSON Schema that defines:

1. **Structure**: What fields are required/optional
2. **Types**: Data types for each field
3. **Validation**: Constraints and formats
4. **Documentation**: Description of what each field means

### Example Domain Schema

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "$id": "https://myapp.com/schemas/user_registration.json",
  "title": "User Registration Context",
  "description": "Context data for user registration process",
  "type": "object",
  "properties": {
    "email": {
      "type": "string",
      "format": "email",
      "description": "User's email address"
    },
    "source": {
      "type": "string",
      "enum": ["web", "mobile", "api"],
      "description": "Registration source channel"
    },
    "referrer": {
      "type": "string",
      "format": "uri",
      "description": "Referrer URL (optional)"
    }
  },
  "required": ["email", "source"],
  "additionalProperties": false
}
```

## Hierarchical Logging

For nested operations (like embed in BEAR.Resource), use nested `open` entries:

```json
{
  "open": {
    "type": "page_render",
    "$schema": "https://myapp.com/schemas/page_render.json",
    "context": {"page": "/user/profile"},
    "open": {
      "type": "user_data_fetch",
      "$schema": "https://myapp.com/schemas/user_data_fetch.json", 
      "context": {"user_id": "usr_123"}
    }
  }
}
```

## Best Practices

1. **Always provide schemas**: Every `type` should have a corresponding schema
2. **Use meaningful types**: `user_login` not `process_start`
3. **Document everything**: Schemas serve as API documentation
4. **Version your schemas**: Use versioned URLs for schema evolution
5. **Validate early**: Validate context data before logging

## Tools

- **JSON Schema Validators**: Validate log files against schemas
- **Code Generators**: Generate context classes from schemas
- **Documentation**: Auto-generate docs from schemas
- **AI Analysis**: AI can understand log meaning through schemas