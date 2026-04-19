<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use JsonSerializable;
use Override;

use function array_map;

final class Profile implements JsonSerializable
{
    /**
     * @param array<string, OperationProfile> $operations Per-operation profile segments keyed by open id.
     *                                                    Each OperationProfile captures the wall time and
     *                                                    one-or-more trace/xhprof segments attributable to
     *                                                    that specific operation. Nested opens split a
     *                                                    parent operation into multiple segments.
     */
    public function __construct(
        public readonly PhpProfile|null $php = null,
        public readonly array $operations = [],
    ) {
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        $result = [
            'php' => $this->getPhpSummary(),
        ];

        if (! empty($this->operations)) {
            $result['operations'] = array_map(
                static fn (OperationProfile $op): array => $op->jsonSerialize(),
                $this->operations,
            );
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function getPhpSummary(): array
    {
        if ($this->php === null) {
            return ['backtrace' => []];
        }

        $summary = $this->php->jsonSerialize();
        $summary['total_wall_time'] = $this->php->getTotalWallTime();

        return $summary;
    }
}
