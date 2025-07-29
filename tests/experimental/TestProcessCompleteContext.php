<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental;

use Koriym\SemanticLogger\AbstractContext;

class TestProcessCompleteContext extends AbstractContext
{
    public const TYPE = 'process_complete';
    public const SCHEMA_URL = 'https://example.com/schemas/process_complete.json';

    public function __construct(
        public readonly int $processId,
        public readonly string $status,
        public readonly string $message,
    ) {
    }
}
