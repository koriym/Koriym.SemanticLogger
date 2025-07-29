<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting;

/**
 * Result of a single log-driven test execution
 */
final class TestResult
{
    public function __construct(
        public readonly string $operationId,
        public readonly string $operationType,
        public readonly bool $passed,
        public readonly mixed $expected,
        public readonly mixed $actual,
        public readonly string|null $errorMessage,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'operation_type' => $this->operationType,
            'passed' => $this->passed,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'error_message' => $this->errorMessage,
        ];
    }
}
