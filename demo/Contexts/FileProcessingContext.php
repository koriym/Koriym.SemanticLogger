<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * File Processing Context for file operations
 */
final class FileProcessingContext extends AbstractContext
{
    public const TYPE = 'file_processing';
    public const SCHEMA_URL = '../schemas/file_processing.json';

    public function __construct(
        public readonly string $operation,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $fileSize,
        public readonly float $processingTime,
        public readonly bool $success,
        public readonly string|null $outputPath = null,
    ) {
    }
}
