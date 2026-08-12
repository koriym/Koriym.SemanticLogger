<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use RuntimeException;

/** A context whose jsonSerialize() always fails, for diagnostics testing. */
final class UnserializableContext extends AbstractContext implements JsonSerializable
{
    public const TYPE = 'unserializable_context';
    public const SCHEMA_URL = 'https://example.com/schemas/unserializable.json';

    public function __construct(
        public readonly string $note = 'broken',
    ) {
    }

    public function jsonSerialize(): mixed
    {
        throw new RuntimeException('cannot serialize');
    }
}
