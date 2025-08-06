<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Cache Operation Context for caching operations
 */
final class CacheOperationContext extends AbstractContext
{
    public const TYPE = 'cache_operation';
    public const SCHEMA_URL = '../schemas/cache_operation.json';

    public function __construct(
        public readonly string $operation,
        public readonly string $key,
        public readonly bool $hit,
        public readonly float $duration,
        public readonly int $ttl = 0,
        public readonly int $size = 0,
    ) {
    }
}
