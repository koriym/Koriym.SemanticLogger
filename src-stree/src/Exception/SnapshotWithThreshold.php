<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree\Exception;

/**
 * Snapshot output was asked for together with a time threshold.
 *
 * Which nodes a threshold keeps depends on the timings of the run, so the tree would differ
 * between runs. The CLI rejects `--threshold=0ms` too. Drop the threshold for snapshot tests.
 *
 * Message format: the threshold in seconds
 */
final class SnapshotWithThreshold extends RuntimeException
{
    public function __construct(public readonly float $threshold)
    {
        parent::__construct((string) $threshold);
    }
}
