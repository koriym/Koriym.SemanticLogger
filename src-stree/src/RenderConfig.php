<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use Koriym\SemanticLogger\Stree\Exception\RuntimeException;

final class RenderConfig
{
    /**
     * @param bool                   $showFullTree  Show full tree with all context keys as leaves
     * @param float                  $timeThreshold Minimum execution time to display (in seconds)
     * @param int                    $maxLines      Maximum lines for multi-line data (default: 5, 0 = no limit)
     * @param bool                   $showValues    Opt-in flag for formatters that may render values (default: false)
     * @param FormatterRegistry|null $formatters    Optional registry of per-type formatters (overrides SignalExtractor)
     * @param bool                   $snapshot      Omit per-run values (execution times, close profiles) so the same operation renders byte-identical output; formatters should honor it too
     *
     * @throws RuntimeException When $snapshot is combined with a time threshold, whose filtering depends on per-run timings.
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function __construct(
        public readonly bool $showFullTree,
        public readonly float $timeThreshold,
        public readonly int $maxLines,
        public readonly bool $showValues = false,
        public readonly FormatterRegistry|null $formatters = null,
        public readonly bool $snapshot = false,
    ) {
        if ($snapshot && $timeThreshold > 0) {
            throw new RuntimeException('Snapshot output cannot be combined with a time threshold');
        }
    }
}
