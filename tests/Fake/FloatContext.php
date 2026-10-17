<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

final class FloatContext extends AbstractContext
{
    public const TYPE = 'float_context';
    public const SCHEMA_URL = 'https://example.com/schemas/float.json';

    public function __construct(
        public readonly float $durationMs,
    ) {
    }
}
