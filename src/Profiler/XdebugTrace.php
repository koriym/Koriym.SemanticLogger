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
use function is_string;
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

    /** @codeCoverageIgnore */
    public static function start(): self
    {
        if (! function_exists('xdebug_start_trace')) {
            return new self(); // @codeCoverageIgnore
        }

        // Non-destructive: if an external trace is already running, don't touch it.
        // Use empty() so both "not running" runtime shapes (false and '') fall
        // through to the start path; stubs advertise string but Xdebug 3 returns
        // bool(false) when no trace is active.
        if (function_exists('xdebug_get_tracefile_name') && ! empty(@xdebug_get_tracefile_name())) {
            return new self(); // @codeCoverageIgnore
        }

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

    /** @codeCoverageIgnore */
    public function stop(): self
    {
        if (! $this->canStopTrace()) {
            return new self(); // @codeCoverageIgnore
        }

        return $this->performStopTrace(); // @codeCoverageIgnore
    }

    private function canStopTrace(): bool
    {
        // Only stop traces we started ourselves (traceId set) or whose content we already hold.
        // Instances returned as no-ops from start() have neither and must not touch external traces.
        return $this->traceId !== null || $this->content !== null;
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

        if (! is_string($traceFile) || ! file_exists($traceFile)) {
            return new self(); // @codeCoverageIgnore
        }

        // Keep the trace file for reference instead of deleting it
        $content = file_get_contents($traceFile);
        if ($content === false) {
            return new self(); // @codeCoverageIgnore
        }

        return new self($content, $traceFile);
    }

    /** @codeCoverageIgnore */
    public function getFilePath(): string|null
    {
        return $this->filePath;
    }

    /** @codeCoverageIgnore */
    public function getFileSize(): int
    {
        if ($this->filePath === null || ! file_exists($this->filePath)) {
            return 0;
        }

        $size = filesize($this->filePath);

        return $size !== false ? $size : 0;
    }

    /** @codeCoverageIgnore */
    public function isCompressed(): bool
    {
        return $this->filePath !== null && str_ends_with($this->filePath, '.gz');
    }

    /**
     * @return array<string, mixed>
     *
     * @codeCoverageIgnore
     */
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
