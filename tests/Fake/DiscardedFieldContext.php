<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

final class DiscardedFieldContext extends AbstractContext
{
    public const TYPE = 'discarded_field';
    public const SCHEMA_URL = 'https://example.com/schemas/discarded-field.json';

    /** @param list<string> $discardedContext */
    public function __construct(
        public readonly array $discardedContext = [],
    ) {
    }
}
