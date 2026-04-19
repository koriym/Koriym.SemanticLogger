<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

final class RenderConfig
{
    /**
     * @param bool                   $showFullTree  Show full tree with all context keys as leaves
     * @param float                  $timeThreshold Minimum execution time to display (in seconds)
     * @param int                    $maxLines      Maximum lines for multi-line data (default: 5, 0 = no limit)
     * @param bool                   $showValues    Opt-in flag for formatters that may render values (default: false)
     * @param FormatterRegistry|null $formatters    Optional registry of per-type formatters (overrides SignalExtractor)
     */
    public function __construct(
        public readonly bool $showFullTree,
        public readonly float $timeThreshold,
        public readonly int $maxLines,
        public readonly bool $showValues = false,
        public readonly FormatterRegistry|null $formatters = null,
    ) {
    }
}
