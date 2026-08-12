<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;

/** A context whose jsonSerialize() returns a DTO (a non-stdClass object). */
final class DtoSerializingContext extends AbstractContext implements JsonSerializable
{
    public const TYPE = 'dto_serializing_context';
    public const SCHEMA_URL = 'https://example.com/schemas/dto-serializing.json';

    /** @var string Distractor: must NOT be recorded in place of the serialized form. */
    public string $fallback = 'fallback';

    public function jsonSerialize(): mixed
    {
        return new class {
            public string $serialized = 'value';
        };
    }
}
