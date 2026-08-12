<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use function count;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Knowledge of the core-owned diagnostic vocabulary.
 *
 * Diagnostic entries (semantic_logger_error / semantic_logger_invalid_context)
 * are recorded by the logger itself, so they are listed with their own
 * severity: they are not violations. The log is data, and CI decides whether
 * their presence fails a run.
 */
final class CoreDiagnostic
{
    public function isCoreType(string $type): bool
    {
        return $this->schemaUrlFor($type) !== null;
    }

    /** Return the canonical schema URL for a core-owned type, or null for any other type. */
    public function schemaUrlFor(string $type): string|null
    {
        return match ($type) {
            CoreSchema::DIAGNOSTIC_TYPE => CoreSchema::DIAGNOSTIC_URL,
            CoreSchema::INVALID_CONTEXT_TYPE => CoreSchema::INVALID_CONTEXT_URL,
            default => null,
        };
    }

    /** Build a one-line description of a logger-recorded diagnostic entry. */
    public function describe(object $entry, string $path, string $type): string
    {
        $context = $entry->context ?? null;
        if (! is_object($context)) {
            return "[{$path}] {$type}";
        }

        // semantic_logger_error carries kind/message at the top level;
        // semantic_logger_invalid_context carries them in errors[0].
        $source = $this->diagnosticSource($context);
        $kind = $this->stringProperty($source, 'kind');
        $message = $this->stringProperty($source, 'message');
        if ($kind !== null && $message !== null) {
            return "[{$path}] {$type} ({$kind}): {$message}";
        }

        if ($kind !== null) {
            return "[{$path}] {$type} ({$kind})";
        }

        return "[{$path}] {$type}";
    }

    /**
     * List the diagnostic entries found during validation.
     *
     * @param list<string> $diagnostics
     */
    public function report(array $diagnostics): void
    {
        if ($diagnostics === []) {
            return;
        }

        echo sprintf("⚠️  The logger recorded %d diagnostic entries:\n", count($diagnostics));
        foreach ($diagnostics as $diagnostic) {
            echo "  {$diagnostic}\n";
        }
    }

    private function diagnosticSource(object $context): object
    {
        /** @var mixed $errors */
        $errors = $context->errors ?? null;
        if (! is_array($errors)) {
            return $context;
        }

        /** @var mixed $first */
        $first = $errors[0] ?? null;

        return is_object($first) ? $first : $context;
    }

    private function stringProperty(object $data, string $name): string|null
    {
        /** @var mixed $value */
        $value = $data->{$name} ?? null;

        return is_string($value) ? $value : null;
    }
}
