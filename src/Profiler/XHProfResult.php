<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use JsonSerializable;
use Override;

use function count;
use function date;
use function file_put_contents;
use function function_exists;
use function json_encode;
use function md5;
use function sys_get_temp_dir;
use function xhprof_disable;
use function xhprof_enable;

use const JSON_PRETTY_PRINT;
use const XHPROF_FLAGS_CPU;
use const XHPROF_FLAGS_MEMORY;
use const XHPROF_FLAGS_NO_BUILTINS;

final class XHProfResult implements JsonSerializable
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public readonly array|null $data = null,
        public readonly string|null $filePath = null,
    ) {
    }

    /**
     * @param list<string> $ignoredFunctions Additional function/method names to exclude from the call graph.
     *     Unsafe for any name that is an ancestor of one profiling segment's xhprof_enable() call and, later,
     *     an ancestor of a *different* segment's xhprof_disable() call (the exact shape that crashed xhprof for
     *     DevSemanticLogger::close() — see the comment below). Do not pass names of methods that themselves call
     *     XHProfResult::start()/stop() across nested open/close boundaries, such as framework-level logger methods.
     */
    public static function start(array $ignoredFunctions = []): self
    {
        if (! function_exists('xhprof_enable')) {
            return new self(); // @codeCoverageIgnore
        }

        // Deliberately NOT ignoring our own stop/teardown frames (close(),
        // stopAndAttachTo(), self::stop(), ...): xhprof's ignored_functions
        // segfaults when a name in the list is both an ancestor of this
        // xhprof_enable() call and later an ancestor of a *different*
        // segment's xhprof_disable() call (DevSemanticLogger::close()
        // starts the resumed-parent segment and a later close() stops it).
        // Callers can still add their own names via $ignoredFunctions as
        // long as those names don't span enable/disable boundaries the
        // same way.
        $ignored = [
            XdebugTrace::class . '::stop',
            XdebugTrace::class . '::canStopTrace',
            XdebugTrace::class . '::performStopTrace',
            ...$ignoredFunctions,
        ];

        // NO_BUILTINS drops PHP internal functions (count, array_*, strlen, ...)
        // which otherwise dominate the call graph and drown out application
        // hotspots when this output is fed to AI for analysis.
        /** @psalm-suppress UndefinedConstant, MixedArgument */
        xhprof_enable(
            XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_NO_BUILTINS,
            ['ignored_functions' => $ignored],
        );

        return new self();
    }

    /** @codeCoverageIgnore */
    public function stop(string $uri): self
    {
        if (! function_exists('xhprof_disable')) {
            return new self(); // @codeCoverageIgnore
        }

        $xhprofData = xhprof_disable();

        // xhprof_disable() returns array|false according to PHPStan
        /** @psalm-suppress TypeDoesNotContainType */
        if ($xhprofData === false) { /** @phpstan-ignore-line identical.alwaysFalse */
            return new self(); // @codeCoverageIgnore
        }

        if (count($xhprofData) === 0) {
            return new self();
        }

        // Save data to file and return reference
        /** @var array<string, mixed> $xhprofData */
        $filePath = $this->saveToFile($xhprofData, $uri);

        return new self($xhprofData, $filePath);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @codeCoverageIgnore
     */
    private function saveToFile(array $data, string $uri): string
    {
        $filename = 'xhprof_' . date('Y-m-d_H-i-s') . '_' . md5($uri) . '.json';
        $filePath = sys_get_temp_dir() . '/' . $filename;

        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) {
            $json = '{}';
        }

        file_put_contents($filePath, $json);

        return $filePath;
    }

    /**
     * @return array<string, mixed>
     *
     * @codeCoverageIgnore
     */
    #[Override]
    public function jsonSerialize(): array
    {
        if ($this->data === null) {
            return [];
        }

        return [
            'path' => $this->filePath,
        ];
    }
}
