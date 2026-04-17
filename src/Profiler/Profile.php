<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use JsonSerializable;
use Override;

final class Profile implements JsonSerializable
{
    /** @param array<string, float> $operationWallTimes operation ID => wall time in seconds */
    public function __construct(
        public XHProfResult|null $xhprof = null,
        public XdebugTrace|null $xdebug = null,
        public PhpProfile|null $php = null,
        public array $operationWallTimes = [],
    ) {
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        $result = [
            'xhprof' => $this->getXhprofSummary(),
            'xdebug' => $this->getXdebugSummary(),
            'php' => $this->getPhpSummary(),
        ];

        if (! empty($this->operationWallTimes)) {
            $result['operations'] = $this->operationWallTimes;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function getXhprofSummary(): array
    {
        if ($this->xhprof === null) {
            return [];
        }

        return [
            'source' => $this->xhprof->filePath,
        ];
    }

    /** @return array<string, mixed> */
    private function getXdebugSummary(): array
    {
        if ($this->xdebug === null) {
            return [];
        }

        return [
            'source' => $this->xdebug->getFilePath(),
            'file_size' => $this->xdebug->getFileSize(),
            'compressed' => $this->xdebug->isCompressed(),
        ];
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
