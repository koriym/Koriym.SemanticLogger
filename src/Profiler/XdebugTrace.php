<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use JsonSerializable;
use Override;

use function file_exists;
use function file_get_contents;
use function filesize;
use function function_exists;
use function ini_get;
use function rtrim;
use function str_ends_with;
use function sys_get_temp_dir;
use function uniqid;
use function xdebug_get_tracefile_name;
use function xdebug_start_trace;
use function xdebug_stop_trace;

final class XdebugTrace implements JsonSerializable
{
    private string|null $traceId = null;

    public function __construct(
        public readonly string|null $content = null,
        public readonly string|null $filePath = null,
    ) {
    }

    public static function start(): self
    {
        if (! function_exists('xdebug_start_trace')) {
            return new self(); // @codeCoverageIgnore
        }

        // Always start our own trace to ensure we have control over the file format
        // Stop any existing trace first to ensure we get a fresh start
        @xdebug_stop_trace(); // @codeCoverageIgnore - suppress errors if not running

        $instance = new self();
        $instance->traceId = uniqid('profile_', true);

        // Use full path for trace file to ensure consistency with xhprofFile
        $outputDir = ini_get('xdebug.output_dir');
        if ($outputDir === false) {
            $outputDir = sys_get_temp_dir(); // @codeCoverageIgnore
        }

        $traceFilePrefix = rtrim($outputDir, '/') . '/' . $instance->traceId;
        xdebug_start_trace($traceFilePrefix); // @codeCoverageIgnore

        // Note: Return value is void, trace may fail silently if already started elsewhere
        return $instance;
    }

    public function stop(): self
    {
        if (! $this->canStopTrace()) {
            return new self(); // @codeCoverageIgnore
        }

        return $this->performStopTrace(); // @codeCoverageIgnore
    }

    private function canStopTrace(): bool
    {
        // Can stop if we started the trace ourselves, OR if there's an existing trace running
        return $this->traceId !== null || function_exists('xdebug_get_tracefile_name');
    }

    private function performStopTrace(): self
    {
        // If we already have content (from existing trace), preserve it
        if ($this->content !== null) {
            return new self($this->content); // @codeCoverageIgnore
        }

        // Get the trace file name BEFORE stopping the trace
        $traceFile = function_exists('xdebug_get_tracefile_name') ? xdebug_get_tracefile_name() : false; // @codeCoverageIgnore
        @xdebug_stop_trace(); // @codeCoverageIgnore - suppress errors if not running

        if ($traceFile === false || ! file_exists($traceFile)) {
            return new self(); // @codeCoverageIgnore
        }

        // Keep the trace file for reference instead of deleting it
        $content = file_get_contents($traceFile);
        if ($content === false) {
            return new self(); // @codeCoverageIgnore
        }

        return new self($content, $traceFile);
    }

    public function getFilePath(): string|null
    {
        return $this->filePath;
    }

    public function getFileSize(): int
    {
        if ($this->filePath === null || ! file_exists($this->filePath)) {
            return 0;
        }

        $size = filesize($this->filePath);

        return $size !== false ? $size : 0;
    }

    public function isCompressed(): bool
    {
        return $this->filePath !== null && str_ends_with($this->filePath, '.gz');
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        if ($this->content === null) {
            return [];
        }

        return [
            'source' => $this->filePath, // file path or inline data
        ];
    }
}
