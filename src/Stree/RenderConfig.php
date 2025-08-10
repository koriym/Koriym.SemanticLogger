<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

final class RenderConfig
{
    /**
     * @param int      $maxDepth      Maximum depth to render (default: 2)
     * @param string[] $expandTypes   Context types to expand beyond maxDepth
     * @param float    $timeThreshold Minimum execution time to display (in seconds)
     * @param bool     $showFullTree  Show complete tree ignoring depth limits
     * @param int      $maxLines      Maximum lines to show for multi-line data (default: 5, 0 = no limit)
     */
    public function __construct(
        public readonly int $maxDepth,
        public readonly array $expandTypes,
        public readonly float $timeThreshold,
        public readonly bool $showFullTree,
        public readonly int $maxLines,
    ) {
    }
}
