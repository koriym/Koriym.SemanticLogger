<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Performance Metrics Context for performance monitoring
 */
final class PerformanceMetricsContext extends AbstractContext
{
    public const TYPE = 'performance_metrics';
    public const SCHEMA_URL = './schemas/performance_metrics.json';

    public function __construct(
        public readonly float $executionTime,
        public readonly int $memoryUsed,
        public readonly int $peakMemory,
        public readonly int $databaseQueries,
        public readonly float $totalQueryTime,
        public readonly int $cacheHits,
        public readonly int $cacheMisses,
        public readonly array $functionCalls,
    ) {
    }
}
