<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use JsonSerializable;
use Override;

use function file_exists;
use function file_get_contents;
use function function_exists;
use function getenv;
use function ini_get;
use function is_string;
use function rtrim;
use function str_contains;
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
        if (! self::isTraceAvailable()) {
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

        // Use a concrete path so the resulting trace file can be referenced
        // directly. On macOS, Xdebug tracing to /var/tmp can segfault in some
        // PHP/Xdebug builds, so prefer the process temp dir in that case.
        $outputDir = self::traceOutputDir();
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

        if (! self::isTraceAvailable()) {
            return new self(); // @codeCoverageIgnore
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

    private static function isTraceAvailable(): bool
    {
        if (! function_exists('xdebug_start_trace')) {
            return false;
        }

        $envMode = getenv('XDEBUG_MODE');
        $iniMode = ini_get('xdebug.mode');
        $mode = $envMode !== false ? $envMode : ($iniMode !== false ? $iniMode : '');

        return str_contains($mode, 'trace');
    }

    private static function traceOutputDir(): string
    {
        $outputDir = ini_get('xdebug.output_dir');
        if (! is_string($outputDir) || $outputDir === '') {
            return sys_get_temp_dir();
        }

        if (PHP_OS_FAMILY === 'Darwin' && rtrim($outputDir, '/') === '/var/tmp') {
            return sys_get_temp_dir();
        }

        return $outputDir;
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
            'path' => $this->filePath,
        ];
    }
}
