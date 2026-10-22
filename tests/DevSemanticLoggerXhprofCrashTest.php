<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function fclose;
use function is_array;
use function is_resource;
use function json_decode;
use function proc_close;
use function proc_open;
use function stream_get_contents;

use const PHP_BINARY;

/**
 * Regression test for a segfault that only reproduces as a real OS-level
 * crash in the xhprof extension (SIGSEGV), so the scenario must run in a
 * child PHP process: a segfault here would otherwise kill the PHPUnit
 * process itself instead of failing one assertion.
 */
final class DevSemanticLoggerXhprofCrashTest extends TestCase
{
    public function testNestedCloseUnderXhprofDoesNotSegfault(): void
    {
        $xhprofArgs = $this->xhprofArgs();
        if ($xhprofArgs === null) {
            self::markTestSkipped('xhprof extension is not loadable in a child PHP process');
        }

        $fixture = __DIR__ . '/Fake/xhprof-nested-close-smoke.php';
        [$exitCode, $stdout, $stderr] = $this->runChildProcess([PHP_BINARY, ...$xhprofArgs, $fixture]);

        // Exit 139 (128 + SIGSEGV) is the regression this test guards
        // against: DevSemanticLogger's nested open/close used to crash
        // xhprof via self-referencing ignored_functions entries in
        // XHProfResult::start().
        self::assertSame(0, $exitCode, 'child process crashed (exit ' . $exitCode . '): ' . $stderr);

        $log = json_decode($stdout, true);
        if (! is_array($log) || ! isset($log['entries']) || ! is_array($log['entries'])) {
            self::fail('Expected child process to print a decodable semantic log with an entries list, got: ' . $stdout);
        }

        $closes = $this->collectCloses($log['entries']);
        self::assertCount(4, $closes, 'all four nested closes (l3, l3b, l2, l1) must be present');

        foreach ($closes as $close) {
            $profile = $close['profile'] ?? null;
            if (! is_array($profile)) {
                self::fail('Expected each close to carry an array profile.');
            }

            self::assertArrayHasKey('xhprofProfile', $profile, 'profile must include xhprof data');
            self::assertNotSame([], $profile['xhprofProfile'] ?? null, 'xhprof segment list must not be empty');
        }
    }

    /**
     * Loading an extension that php.ini already loads prints a startup warning to stdout,
     * which would corrupt the child's JSON output, so `-d extension=xhprof` is added only when needed.
     *
     * @return list<string>|null null when xhprof cannot be loaded at all
     */
    private function xhprofArgs(): array|null
    {
        $probeCode = 'exit(extension_loaded("xhprof") ? 0 : 1);';
        foreach ([[], ['-d', 'extension=xhprof']] as $args) {
            [$exitCode] = $this->runChildProcess([PHP_BINARY, ...$args, '-r', $probeCode]);
            if ($exitCode === 0) {
                return $args;
            }
        }

        return null;
    }

    /**
     * @param list<string> $command
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runChildProcess(array $command): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            self::fail('Failed to start child PHP process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [$exitCode, $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
    }

    /**
     * @param array<mixed> $entries
     *
     * @return list<array<array-key, mixed>>
     */
    private function collectCloses(array $entries): array
    {
        $closes = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                self::fail('Expected each entry to be an array.');
            }

            if (isset($entry['close']) && is_array($entry['close'])) {
                $closes[] = $entry['close'];
            }

            if (isset($entry['children']) && is_array($entry['children'])) {
                foreach ($this->collectCloses($entry['children']) as $nested) {
                    $closes[] = $nested;
                }
            }
        }

        return $closes;
    }
}
