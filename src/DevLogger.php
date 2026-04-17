<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Throwable;

use function date;
use function error_log;
use function file_put_contents;
use function getmypid;
use function json_encode;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;

/** Writes semantic log JSON to a file for development/debugging purposes */
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

    public function log(SemanticLoggerInterface $logger): void
    {
        try {
            $logData = $logger->flush();
            $this->saveToFile($logData);
        } catch (Throwable $e) {
            // Log the error for debugging while preserving application flow
            error_log('DevLogger: Failed to write semantic log - ' . $e->getMessage());
        }
    }

    public function saveToFile(LogJson $logData): void
    {
        $jsonContent = json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($jsonContent === false) {
            error_log('DevLogger: JSON encoding failed for semantic log data');

            return; // Skip if JSON encoding fails
        }

        $filename = $this->generateFilename();
        file_put_contents($filename, $jsonContent, LOCK_EX);
    }

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

}
