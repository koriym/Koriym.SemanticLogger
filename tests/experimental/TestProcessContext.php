<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental;

use Koriym\SemanticLogger\AbstractContext;

class TestProcessContext extends AbstractContext
{
    public const TYPE = 'process_start';
    public const SCHEMA_URL = 'https://example.com/schemas/process_start.json';

    public function __construct(
        public readonly string $message,
        public readonly int $id,
    ) {
    }
}
