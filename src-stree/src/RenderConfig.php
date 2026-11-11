<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use Koriym\SemanticLogger\Stree\Exception\SchemaDirNotFound;
use Koriym\SemanticLogger\Stree\Exception\SchemaDirWithoutSnapshot;
use Koriym\SemanticLogger\Stree\Exception\SnapshotWithThreshold;

use function is_dir;

final class RenderConfig
{
    /**
     * @param bool                   $showFullTree  Show full tree with all context keys as leaves
     * @param float                  $timeThreshold Minimum execution time to display (in seconds)
     * @param int                    $maxLines      Maximum lines for multi-line data (default: 5, 0 = no limit)
     * @param bool                   $showValues    Opt-in flag for formatters that may render values (default: false)
     * @param FormatterRegistry|null $formatters    Optional registry of per-type formatters (overrides SignalExtractor)
     * @param bool                   $snapshot      Omit per-run values (execution times, close profiles) so the same operation renders byte-identical output; formatters should honor it too
     * @param string|null            $schemaDir     Context schemas whose `"x-volatile": true` values render as `[volatile]` in snapshot output
     *
     * @throws SnapshotWithThreshold
     * @throws SchemaDirWithoutSnapshot
     * @throws SchemaDirNotFound
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
        public readonly string|null $schemaDir = null,
    ) {
        if ($snapshot && $timeThreshold > 0) {
            throw new SnapshotWithThreshold($timeThreshold);
        }

        if ($schemaDir === null) {
            return;
        }

        if (! $snapshot) {
            throw new SchemaDirWithoutSnapshot($schemaDir);
        }

        if (! is_dir($schemaDir)) {
            throw new SchemaDirNotFound($schemaDir);
        }
    }
}
