<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Throwable;

use function date;
use function file_put_contents;
use function getmypid;
use function json_encode;
use function sprintf;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;

/**
 * Development logger for MCP server integration
 *
 * Handles file output of semantic logs with Profile data for AI-assisted debugging.
 * Uses single responsibility principle - only concerned with development log output.
 */
final class DevLogger
{
    public function __construct(
        private string $logDirectory = '',
    ) {
        // Use system temp directory if no directory specified
        if ($this->logDirectory !== '') {
            return;
        }

        $this->logDirectory = sys_get_temp_dir();
    }

    /**
     * Output semantic logs to file for development/debugging purposes
     */
    public function log(SemanticLoggerInterface $logger): void
    {
        try {
            $logData = $logger->flush();
            $this->saveToFile($logData);
        } catch (Throwable) {
            // Silent failure - don't break main processing
            // In development, log persistence should never affect application flow
        }
    }

    private function saveToFile(LogJson $logData): void
    {
        $jsonContent = json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($jsonContent === false) {
            return; // Skip if JSON encoding fails
        }

        $filename = $this->generateFilename();
        file_put_contents($filename, $jsonContent, LOCK_EX);

        // Save analysis prompt alongside JSON for AI-native processing
        $promptFilename = str_replace('.json', '-prompt.md', $filename);
        $analysisPrompt = $this->getAnalysisPrompt() . "\n\n```json\n" . $jsonContent . "\n```";
        file_put_contents($promptFilename, $analysisPrompt, LOCK_EX);
    }

    /**
     * Generate unique filename for concurrent request safety
     */
    private function generateFilename(): string
    {
        $timestamp = date('Y-m-d_H-i-s-u'); // Microseconds for concurrency
        $processId = getmypid(); // Process ID for true uniqueness
        $uniqueId = uniqid();

        return sprintf(
            '%s/semantic-dev-%s-%s-%s.json',
            $this->logDirectory,
            $timestamp,
            $processId !== false ? $processId : 'unknown',
            $uniqueId,
        );
    }

    /**
     * Generate analysis prompt for AI-native semantic web processing
     */
    private function getAnalysisPrompt(): string
    {
        return <<<'PROMPT'
This is a semantic profiling log. Analyze YOUR APPLICATION CODE performance, not the framework itself.

The log contains schemaUrl fields. Refer to these schemas to understand the semantic meaning of the data structures.

Focus on business logic within resource methods and application-specific code. Ignore framework overhead and profiling overhead.

If no performance issues are found, provide:
- What the code is doing (business purpose)
- How it's implemented (technical approach)
- Implementation assessment (is this approach appropriate for the task?)
- Any architectural observations or suggestions for production readiness

Use the semantic profiling data to provide deep insights about code quality and appropriateness, not just performance metrics.
PROMPT;
    }
}
