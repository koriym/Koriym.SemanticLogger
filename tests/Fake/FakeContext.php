<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

final class FakeContext extends AbstractContext
{
    public const TYPE = 'example_event';
    public const SCHEMA_URL = 'https://example.com/schemas/example.json';

    public function __construct(
        public readonly string $message,
        public readonly int $value = 0,
    ) {
    }
}
