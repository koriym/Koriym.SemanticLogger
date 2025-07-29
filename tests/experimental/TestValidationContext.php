<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental;

use Koriym\SemanticLogger\AbstractContext;

class TestValidationContext extends AbstractContext
{
    public const TYPE = 'validation';
    public const SCHEMA_URL = 'https://example.com/schemas/validation.json';

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $rules,
        public readonly array $data,
    ) {
    }
}
