<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use JsonSerializable;
use Override;

final class OperationProfile implements JsonSerializable
{
    /**
     * Per-segment profiler artifacts captured while this operation was active.
     *
     * @param list<XdebugTrace>  $xdebugTrace
     * @param list<XHProfResult> $xhprofProfile
     */
    public function __construct(
        public readonly float $wallTime,
        public readonly array $xdebugTrace = [],
        public readonly array $xhprofProfile = [],
    ) {
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        $serialized = [
            'wallTime' => $this->wallTime,
        ];

        $xdebugTrace = $this->serializeXdebugTraceSegments();
        if ($xdebugTrace !== []) {
            $serialized['xdebugTrace'] = $xdebugTrace;
        }

        $xhprofProfile = $this->serializeXhprofProfileSegments();
        if ($xhprofProfile !== []) {
            $serialized['xhprofProfile'] = $xhprofProfile;
        }

        return $serialized;
    }

    public function hasProfilerData(): bool
    {
        return $this->serializeXdebugTraceSegments() !== [] || $this->serializeXhprofProfileSegments() !== [];
    }

    /** @return list<array{path: string}> */
    private function serializeXdebugTraceSegments(): array
    {
        $result = [];
        foreach ($this->xdebugTrace as $segment) {
            $filePath = $segment->getFilePath();
            if ($filePath === null) {
                continue;
            }

            $result[] = ['path' => $filePath];
        }

        return $result;
    }

    /** @return list<array{path: string}> */
    private function serializeXhprofProfileSegments(): array
    {
        $result = [];
        foreach ($this->xhprofProfile as $segment) {
            if ($segment->filePath === null) {
                continue;
            }

            $result[] = ['path' => $segment->filePath];
        }

        return $result;
    }
}
