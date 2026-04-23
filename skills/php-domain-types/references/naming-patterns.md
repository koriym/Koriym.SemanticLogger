# Naming Patterns

Use this file when alias names or catalog boundaries are unclear.

## Favor

- Scalar atoms: `UserId`, `OrderId`, `SchemaUrl`, `LocaleCode`
- Lists: `UserIdList`, `ViolationList`, `RouteSegmentList`
- Maps: `UsersById`, `HeadersByName`, `EventsByRequestId`
- Sets: `EnabledFeatureSet`, `OperationIdSet`
- Serialized payloads: `UserResponseData`, `ValidationErrorData`, `WebhookEnvelopeData`
- Concrete serialized entries: `PublicEventArray`, `SchemaLinkArray`

## Split by meaning

- Use different aliases when the same data passes through different meanings.
- Prefer `UserRow` for storage, `UserInputData` for inbound payloads, and `UserResponseData` for outbound payloads.
- Prefer `OperationProfile` for an object and `OperationProfileData` for its serialized array shape.

## Avoid

- `Data`, `Info`, `Items`, `Map`, `Result` with no subject
- One-off aliases for local helper arrays
- Aliases that merely hide `array<string, mixed>` without adding meaning
- Aliases for concrete containers such as `SplStack` unless they are part of a shared public abstraction

## Mixed Analyzer Pattern

When both Psalm and PHPStan are active, keep declarations and imports explicit.

```php
/**
 * @psalm-type UserResponseData = array{id: non-empty-string, email: non-empty-string}
 * @phpstan-type UserResponseData array{id: non-empty-string, email: non-empty-string}
 */
final class Types
{
    private function __construct()
    {
    }
}
```

```php
/**
 * @psalm-import-type UserResponseData from Types
 * @phpstan-import-type UserResponseData from Types
 *
 * @psalm-return UserResponseData
 * @phpstan-return UserResponseData
 */
public function toArray(): array
{
    // ...
}
```

## Decision Rule

If the best alias name still sounds generic, the shape probably is not a domain type yet.
