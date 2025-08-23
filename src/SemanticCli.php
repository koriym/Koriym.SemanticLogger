<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Exception;
use InvalidArgumentException;

use function basename;
use function count;
use function date;
use function file_get_contents;
use function filemtime;
use function filesize;
use function glob;
use function implode;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function number_format;
use function rtrim;
use function sprintf;
use function usort;

use const JSON_PRETTY_PRINT;

/**
 * Command Line Interface for Semantic Profiler
 */
final class SemanticCli
{
    public function __construct(private string $logDirectory)
    {
        if (! is_dir($logDirectory)) {
            throw new InvalidArgumentException("Directory not found: $logDirectory");
        }
    }

    public function listSemanticProfiles(): string
    {
        $pattern = rtrim($this->logDirectory, '/') . '/semantic-log-*.json';
        $files = glob($pattern);

        if ($files === false || empty($files)) {
            return 'No semantic profile files found in directory: ' . $this->logDirectory;
        }

        // Sort by modification time (newest first)
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $fileList = [];
        foreach ($files as $file) {
            $basename = basename($file);
            $size = filesize($file);
            $mtime = filemtime($file);
            if ($size === false || $mtime === false) {
                continue;
            }

            $fileList[] = sprintf(
                '• %s (%s bytes, %s)',
                $basename,
                number_format($size),
                date('Y-m-d H:i:s', $mtime),
            );
        }

        return "Available semantic profile files:\n\n" . implode("\n", $fileList);
    }

    public function getSemanticProfile(): string
    {
        return $this->explainSemanticLog(1);
    }

    public function explainSemanticLog(int $index = 1): string
    {
        $pattern = rtrim($this->logDirectory, '/') . '/semantic-log-*.json';
        $files = glob($pattern);

        if ($files === false || empty($files)) {
            throw new InvalidArgumentException("No semantic log files found in directory: $this->logDirectory");
        }

        // Sort by modification time (newest first)
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        if ($index < 1 || $index > count($files)) {
            throw new InvalidArgumentException("Invalid index: $index. Available files: " . count($files));
        }

        $logFile = $files[$index - 1]; // Convert to 0-based array index

        $logData = $this->getLog($logFile);
        $analysisPrompt = $this->getAnalysisPrompt();

        $jsonData = json_encode($logData, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            throw new Exception('Failed to encode log data as JSON');
        }

        return $analysisPrompt . "\n\n```json\n" . $jsonData . "\n```";
    }

    /** @return array<string, mixed> */
    private function getLog(string $file): array
    {
        $content = file_get_contents($file);

        if ($content === false) {
            return ['error' => 'Failed to read file'];
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            /** @var array<string, mixed> $result */
            $result = $decoded;

            return $result;
        }

        return ['error' => 'Invalid log format'];
    }

    private function getAnalysisPrompt(): string
    {
        return <<<'PROMPT'
This is a SEMANTIC PROFILING log - structured business logic analysis, not low-level Xdebug traces.

SEMANTIC PROFILING focuses on:
- Business workflow analysis (open → events → close patterns)
- Application logic performance (database queries, API calls, cache operations)
- Architectural insights and code quality assessment

IGNORE low-level framework overhead and focus on YOUR APPLICATION CODE.

The log contains schemaUrl fields. Refer to these schemas to understand the semantic meaning of each context:
- business_logic: Core application operations
- database_connection/complex_query: Data access patterns
- external_api_request: Third-party service integrations
- cache_operation: Caching strategy effectiveness
- performance_metrics: Aggregated application metrics

If no performance issues are found, provide:
- What the code is doing (business purpose)
- How it's implemented (technical approach)
- Implementation assessment (is this approach appropriate for the task?)
- Any architectural observations or suggestions for production readiness

Use the semantic profiling data to provide deep insights about code quality and appropriateness, not just performance metrics.

Please respond in the language most appropriate for this context.
PROMPT;
    }
}
