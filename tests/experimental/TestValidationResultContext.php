<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental;

use Koriym\SemanticLogger\AbstractContext;

class TestValidationResultContext extends AbstractContext
{
    public const TYPE = 'validation_complete';
    public const SCHEMA_URL = 'https://example.com/schemas/validation_complete.json';

    /** @param array<string, mixed> $validatedFields */
    public function __construct(
        public readonly bool $valid,
        public readonly array $validatedFields,
    ) {
    }
}
