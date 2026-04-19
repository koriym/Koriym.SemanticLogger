<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use JsonSerializable;
use Override;

final class OperationProfile implements JsonSerializable
{
    /**
     * @param list<XdebugTrace>  $xdebug Per-segment Xdebug traces captured while this operation was active.
     * @param list<XHProfResult> $xhprof Per-segment XHProf snapshots captured while this operation was active.
     */
    public function __construct(
        public readonly float $wallTime,
        public readonly array $xdebug = [],
        public readonly array $xhprof = [],
    ) {
    }

    /** @return array{wallTime: float, xdebug: list<array<string, mixed>>, xhprof: list<array<string, mixed>>} */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'wallTime' => $this->wallTime,
            'xdebug' => $this->serializeXdebugSegments(),
            'xhprof' => $this->serializeXhprofSegments(),
        ];
    }

    /** @return list<array{source: string, file_size: int, compressed: bool}> */
    private function serializeXdebugSegments(): array
    {
        $result = [];
        foreach ($this->xdebug as $segment) {
            $filePath = $segment->getFilePath();
            if ($filePath === null) {
                continue;
            }

            $result[] = [
                'source' => $filePath,
                'file_size' => $segment->getFileSize(),
                'compressed' => $segment->isCompressed(),
            ];
        }

        return $result;
    }

    /** @return list<array{source: string}> */
    private function serializeXhprofSegments(): array
    {
        $result = [];
        foreach ($this->xhprof as $segment) {
            if ($segment->filePath === null) {
                continue;
            }

            $result[] = ['source' => $segment->filePath];
        }

        return $result;
    }
}
