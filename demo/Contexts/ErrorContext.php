<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Error Context for error and exception handling
 */
final class ErrorContext extends AbstractContext
{
    public const TYPE = 'error';
    public const SCHEMA_URL = '../schemas/error.json';

    public function __construct(
        public readonly string $errorType,
        public readonly string $message,
        public readonly int $code,
        public readonly string $file,
        public readonly int $line,
        public readonly array $trace,
        public readonly array $context = [],
    ) {
    }
}
