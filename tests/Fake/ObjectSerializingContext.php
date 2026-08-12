<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;

/** A context whose jsonSerialize() returns an object rather than an array. */
final class ObjectSerializingContext extends AbstractContext implements JsonSerializable
{
    public const TYPE = 'object_serializing_context';
    public const SCHEMA_URL = 'https://example.com/schemas/object-serializing.json';

    /** @var string Distractor: must NOT be recorded in place of the serialized form. */
    public string $fallback = 'fallback';

    public function jsonSerialize(): mixed
    {
        return (object) ['serialized' => 'value'];
    }
}
