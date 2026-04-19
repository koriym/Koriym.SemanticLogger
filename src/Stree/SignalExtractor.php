<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function array_is_list;
use function array_keys;
use function array_map;
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
    private const MAX_SIGNALS = 4;
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
     * Extract up to MAX_SIGNALS meaningful signals from open context.
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

            $diff = $this->diffForCloseKey($key, $formatted, $openContext);
            if ($diff !== null) {
                $diffs[] = $diff;
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

    /** @param array<string, mixed> $openContext */
    private function diffForCloseKey(string $key, string $formatted, array $openContext): string|null
    {
        if (! isset($openContext[$key])) {
            return $key . '=' . $formatted;
        }

        $openFormatted = $this->formatValue($openContext[$key]) ?? '';
        if ($openFormatted === $formatted) {
            return null;
        }

        return $key . '=' . $openFormatted . '→' . $formatted;
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

        // Assoc arrays: render keys (param names, property names — the structural signal).
        if (! array_is_list($arr)) {
            $keys = array_map(static fn (mixed $k) => (string) $k, array_keys($arr));

            return $this->joinWithOverflow($keys, count($arr));
        }

        // List arrays: inline scalar values; mixed-content falls back to count.
        $scalars = [];
        foreach ($arr as $item) {
            if (! is_scalar($item) && $item !== null) {
                return '[' . count($arr) . ' items]';
            }

            $scalars[] = (string) $item;
        }

        return $this->joinWithOverflow($scalars, count($arr));
    }

    /**
     * Join scalar parts into "[a, b, c]"; if the full list overflows
     * MAX_STRING_LENGTH, keep as many as fit and append " +N items".
     *
     * @param string[] $parts
     */
    private function joinWithOverflow(array $parts, int $total): string
    {
        $inline = implode(', ', $parts);
        if (strlen($inline) <= self::MAX_STRING_LENGTH) {
            return '[' . $inline . ']';
        }

        $kept = [];
        $used = 0;
        foreach ($parts as $part) {
            $addition = ($kept === [] ? 0 : 2) + strlen($part); // ", " + part
            if ($used + $addition > self::MAX_STRING_LENGTH) {
                break;
            }

            $kept[] = $part;
            $used += $addition;
        }

        if ($kept === []) {
            return '[' . $total . ' items]';
        }

        $remaining = $total - count($kept);

        return '[' . implode(', ', $kept) . ' +' . $remaining . ' items]';
    }
}
