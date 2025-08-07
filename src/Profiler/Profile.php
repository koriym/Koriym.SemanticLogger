<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use JsonSerializable;
use Override;

final class Profile implements JsonSerializable
{
    public function __construct(
        public XHProfResult|null $xhprof = null,
        public XdebugTrace|null $xdebug = null,
        public PhpProfile|null $php = null,
    ) {
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'xhprof' => $this->getXhprofSummary(),
            'xdebug' => $this->getXdebugSummary(),
            'php' => $this->getPhpSummary(),
        ];
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
