<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use JsonSerializable;
use Override;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function debug_backtrace;
use function microtime;
use function str_contains;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

final class PhpProfile implements JsonSerializable
{
    /** @param array<int, array{file?: string, line?: int, class?: string, function: string, type?: string}> $backtrace */
    public function __construct(
        public readonly array $backtrace = [],
        public readonly float $wallTime = 0.0,
        private readonly float $startTime = 0.0,
    ) {
    }

    public static function start(): self
    {
        $startTime = microtime(true);

        return new self(startTime: $startTime);
    }

    public function stop(int $backtraceLimit = 10): self
    {
        $endTime = microtime(true);
        $wallTime = $endTime - $this->startTime;

        return new self(
            backtrace: self::collectBacktrace($backtraceLimit),
            wallTime: $wallTime,
        );
    }

    /** @codeCoverageIgnore */
    public static function capture(int $backtraceLimit = 10): self
    {
        return new self(
            backtrace: self::collectBacktrace($backtraceLimit),
        );
    }

    /**
     * Collect backtrace information excluding framework internals
     *
     * @param int $limit Maximum number of stack frames to collect
     *
     * @return array<int, array{file?: string, line?: int, class?: string, function: string, type?: string}>
     *
     * @codeCoverageIgnore
     */
    private static function collectBacktrace(int $limit): array
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        // Filter out framework internal calls to focus on user code
        $filtered = array_filter($backtrace, static function (array $frame): bool {
            if (! isset($frame['file'])) {
                return false;
            }

            // Skip BEAR.Resource internal files
            if (str_contains($frame['file'], '/BEAR/Resource/')) {
                return false;
            }

            // Skip Koriym SemanticLogger internal files
            if (str_contains($frame['file'], '/Koriym/SemanticLogger/')) {
                return false;
            }

            // Skip vendor files except user application
            if (str_contains($frame['file'], '/vendor/') && ! str_contains($frame['file'], '/tests/')) {
                return false;
            }

            // Skip PHPUnit test framework files
            return ! str_contains($frame['file'], '/phpunit/');
        });

        // Reset array keys and limit results
        $result = array_slice(array_values($filtered), 0, $limit);

        // Clean up the frames to only include relevant information
        return array_map(static function (array $frame): array {
            $clean = ['function' => $frame['function']];

            if (isset($frame['file'])) {
                $clean['file'] = $frame['file'];
            }

            if (isset($frame['line'])) {
                $clean['line'] = $frame['line'];
            }

            if (isset($frame['class'])) {
                $clean['class'] = $frame['class'];
            }

            if (isset($frame['type'])) {
                $clean['type'] = $frame['type'];
            }

            return $clean;
        }, $result);
    }

    /** @codeCoverageIgnore */
    public function getTotalWallTime(): float
    {
        return $this->wallTime;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'backtrace' => $this->backtrace,
            'wall_time' => $this->wallTime,
        ];
    }
}
