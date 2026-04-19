<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function array_is_list;
use function count;
use function end;
use function explode;
use function implode;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function sprintf;
use function str_contains;
use function strlen;
use function substr;
use function substr_count;

/**
 * Generic signal extractor for compact one-line context rendering.
 *
 * Operates on any context without domain knowledge.
 */
final class SignalExtractor
{
    private const MAX_SIGNALS = 3;
    private const MAX_STRING_LENGTH = 40;

    /** Keys excluded from signal extraction */
    private const EXCLUDE_KEYS = [
        'executionTime',
        'responseTime',
        'duration',
        'processingTime',
        'connectionTime',
        'id',
        'openId',
        'schemaUrl',
    ];

    /**
     * Extract up to 3 meaningful signals from open context.
     *
     * @param  array<string, mixed> $context
     *
     * @return string               e.g. "method=POST uri=/api/orders (+2 more)"
     */
    public function extractSignals(array $context): string
    {
        $signals = [];
        $remaining = 0;

        /** @var mixed $value */
        foreach ($context as $key => $value) {
            if ($this->shouldExcludeKey($key)) {
                continue;
            }

            if (! $this->isMeaningfulScalar($value) && ! $this->isMeaningfulArray($value)) {
                continue;
            }

            if (count($signals) >= self::MAX_SIGNALS) {
                $remaining++;

                continue;
            }

            $formatted = $this->formatValue($value);
            if ($formatted === null) {
                continue;
            }

            $signals[] = $key . '=' . $formatted;
        }

        if ($signals === []) {
            return '';
        }

        $result = implode(' ', $signals);
        if ($remaining > 0) {
            $result .= sprintf(' (+%d more)', $remaining);
        }

        return $result;
    }

    /**
     * Extract close-diff: 1–2 keys that differ from open, or are new in close.
     *
     * @param  array<string, mixed> $openContext
     * @param  array<string, mixed> $closeContext
     *
     * @return string               e.g. "→ success=true" or "→ status=pending→done"
     */
    public function extractCloseDiff(array $openContext, array $closeContext): string
    {
        $diffs = [];

        /** @var mixed $value */
        foreach ($closeContext as $key => $value) {
            if ($this->shouldExcludeKey($key)) {
                continue;
            }

            if (! $this->isMeaningfulScalar($value) && ! $this->isMeaningfulArray($value)) {
                continue;
            }

            $formatted = $this->formatValue($value);
            if ($formatted === null) {
                continue;
            }

            if (! isset($openContext[$key])) {
                // New in close
                $diffs[] = '→ ' . $key . '=' . $formatted;
            } else {
                // Changed from open
                $openFormatted = $this->formatValue($openContext[$key]);
                $openFormatted ??= '';
                if ($openFormatted !== $formatted) {
                    $diffs[] = '→ ' . $key . '=' . $openFormatted . '→' . $formatted;
                }
            }

            if (count($diffs) >= 2) {
                break;
            }
        }

        if ($diffs === []) {
            return '';
        }

        return implode(' ', $diffs);
    }

    /**
     * Determine if the node has a failure status from its close context or children.
     *
     * @param array<string, mixed> $closeContext
     */
    public function hasFailureIndicator(array $closeContext): bool
    {
        /** @var mixed $success */
        $success = $closeContext['success'] ?? null;
        if ($success === false) {
            return true;
        }

        /** @var mixed $status */
        $status = $closeContext['status'] ?? null;
        if (is_string($status) && $status === 'failed') {
            return true;
        }

        /** @var mixed $error */
        $error = $closeContext['error'] ?? null;

        return $error !== null && $error !== false && $error !== '' && $error !== [];
    }

    /**
     * Expand all context keys into leaf lines for --full mode.
     *
     * @param  array<string, mixed> $context
     *
     * @return string[]
     */
    public function expandFull(array $context, string $prefix = ''): array
    {
        $lines = [];

        foreach ($context as $key => $value) {
            if ($this->shouldExcludeKey($key) && $prefix === '') {
                continue;
            }

            $qualifiedKey = $prefix !== '' ? $prefix . '.' . $key : $key;

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $nested = $this->expandFull($value, $qualifiedKey);
                foreach ($nested as $line) {
                    $lines[] = $line;
                }

                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $stringValue = (string) $value;
            $lines[] = $qualifiedKey . ': ' . $stringValue;
        }

        return $lines;
    }

    private function shouldExcludeKey(string $key): bool
    {
        foreach (self::EXCLUDE_KEYS as $excluded) {
            if ($key === $excluded) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a value is meaningful (non-empty, non-zero scalar).
     */
    private function isMeaningfulScalar(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            // Booleans are always meaningful
            return true;
        }

        if (! is_scalar($value)) {
            return false;
        }

        if (is_string($value) && $value === '') {
            return false;
        }

        // Numeric zero is excluded
        return $value !== 0 && $value !== 0.0 && $value !== '0';
    }

    /**
     * Check if an array has meaningful content.
     */
    private function isMeaningfulArray(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        return $value !== [];
    }

    /**
     * Format a value for compact display.
     *
     * @return string|null null if value should be skipped (object/complex)
     */
    public function formatValue(mixed $value): string|null
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $this->formatArray($value);
        }

        if (! is_scalar($value)) {
            return null;
        }

        $str = (string) $value;

        // FQCN shortening: contains backslash → last segment
        if (str_contains($str, '\\') && substr_count($str, '\\') >= 1) {
            $parts = explode('\\', $str);
            $str = end($parts);
        }

        // Truncate long strings
        if (strlen($str) > self::MAX_STRING_LENGTH) {
            $str = substr($str, 0, self::MAX_STRING_LENGTH) . '…';
        }

        return $str;
    }

    /** @param array<mixed> $arr */
    private function formatArray(array $arr): string
    {
        if ($arr === []) {
            return '[]';
        }

        // Only format scalar arrays
        $scalars = [];
        foreach ($arr as $item) {
            if (! is_scalar($item) && $item !== null) {
                // Has non-scalar items → skip in compact
                return '[' . count($arr) . ' items]';
            }

            $scalars[] = (string) $item;
        }

        // Check if it's a list (sequential integer keys)
        if (! array_is_list($arr)) {
            return '[' . count($arr) . ' items]';
        }

        $inline = implode(', ', $scalars);
        if (strlen($inline) > self::MAX_STRING_LENGTH) {
            return '[' . count($arr) . ' items]';
        }

        return '[' . $inline . ']';
    }
}
