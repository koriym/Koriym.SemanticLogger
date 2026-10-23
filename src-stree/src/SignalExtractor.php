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
use function is_numeric;
use function is_scalar;
use function is_string;
use function preg_match;
use function preg_match_all;
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
        'timestamp',
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
            $diff = $this->extractCloseKeyDiff($key, $value, $openContext, $closeContext);
            if ($diff === null) {
                continue;
            }

            $diffs[] = $diff;
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
     * Extract a displayable diff fragment for one close-context entry.
     *
     * @param array<string, mixed> $openContext
     * @param array<string, mixed> $closeContext
     */
    private function extractCloseKeyDiff(
        string|int $key,
        mixed $value,
        array $openContext,
        array $closeContext,
    ): string|null {
        if ($this->shouldExcludeKey($key)) {
            return null;
        }

        if ($key === 'final' && isset($closeContext['exit'])) {
            return null;
        }

        if (! $this->isMeaningfulScalar($value) && ! $this->isMeaningfulArray($value)) {
            return null;
        }

        $formatted = $this->formatValue($value);
        if ($formatted === null) {
            return null;
        }

        return $this->diffForCloseKey((string) $key, $formatted, $openContext);
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

            $qualifiedKey = $this->qualifyKey($prefix, $key);

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $nested = $this->expandFull($value, $qualifiedKey);
                foreach ($nested as $line) {
                    $lines[] = $line;
                }

                continue;
            }

            if (is_bool($value)) {
                $lines[] = $qualifiedKey . ': ' . $this->formatBoolean($value);
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $lines[] = $qualifiedKey . ': ' . (string) $value;
        }

        return $lines;
    }

    private function qualifyKey(string $prefix, string|int $key): string
    {
        if (is_numeric($key)) {
            return $prefix === '' ? '[' . $key . ']' : $prefix . '[' . $key . ']';
        }

        return $prefix !== '' ? $prefix . '.' . $key : $key;
    }

    private function shouldExcludeKey(string|int $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

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
            return $this->formatBoolean($value);
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

        // Truncate long strings by character, not byte, so a multibyte cut never splits a
        // character into an invalid byte sequence, and the drop count (not a bare "…") tells a
        // reader whether the value ended here or was cut — otherwise the two are
        // indistinguishable, and a redacted boundary just past the cut could go unnoticed.
        $str = $this->truncate($str);

        return $str;
    }

    /**
     * Render a boolean the same way in both compact and full mode: "true" / "false", never the
     * PHP string-cast "1" / "" that makes a false value indistinguishable from a missing key.
     */
    private function formatBoolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Cuts at MAX_STRING_LENGTH characters, not bytes, and appends "...(+N)" with the number of
     * characters dropped, so a cut value is never indistinguishable from one that happened to
     * end exactly there. Character-counted via PCRE's UTF-8 mode (`u` modifier, built into core
     * PHP) rather than ext-mbstring, which this package does not otherwise depend on, and via
     * preg_match_all()/preg_match() rather than preg_split() so a large value is counted and cut
     * without ever materializing a per-character array (preg_split's array held one element per
     * character of the whole input, not just the kept prefix; a 5 MB value cost ~130 MB of extra
     * peak memory this way against ~5 MB here). A string that is not valid UTF-8 falls back to a
     * byte-wise cut, where character counting has no defined meaning anyway.
     */
    private function truncate(string $str): string
    {
        // Byte length is always >= character length, so if the bytes already fit, so do the
        // characters — skips the regex entirely for the common case of short values.
        if (strlen($str) <= self::MAX_STRING_LENGTH) {
            return $str;
        }

        $length = preg_match_all('/./su', $str);
        if ($length === false) {
            $byteLength = strlen($str);

            return substr($str, 0, self::MAX_STRING_LENGTH) . '...(+' . ($byteLength - self::MAX_STRING_LENGTH) . ')';
        }

        if ($length <= self::MAX_STRING_LENGTH) {
            return $str;
        }

        $matched = preg_match('/^.{' . self::MAX_STRING_LENGTH . '}/su', $str, $matches);
        if ($matched !== 1) {
            // $length > MAX_STRING_LENGTH already guarantees this pattern matches; this branch
            // is unreachable in practice and exists only so $matches[0] is never read unchecked.
            return substr($str, 0, self::MAX_STRING_LENGTH) . '...(+' . ($length - self::MAX_STRING_LENGTH) . ')'; // @codeCoverageIgnore
        }

        return $matches[0] . '...(+' . ($length - self::MAX_STRING_LENGTH) . ')';
    }

    /**
     * Character length via PCRE's UTF-8 mode; byte length when $str is not valid UTF-8,
     * where character counting has no defined meaning anyway. Shared by truncate() and
     * joinWithOverflow() so the same 40-character budget means the same thing everywhere
     * formatValue()/formatArray() apply it. Counts via preg_match_all(), not preg_split(),
     * for the same O(1)-memory reason as truncate().
     */
    private function charLength(string $str): int
    {
        $length = preg_match_all('/./su', $str);

        return $length === false ? strlen($str) : $length;
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
        if ($this->charLength($inline) <= self::MAX_STRING_LENGTH) {
            return '[' . $inline . ']';
        }

        $kept = [];
        $used = 0;
        foreach ($parts as $part) {
            $addition = ($kept === [] ? 0 : 2) + $this->charLength($part); // ", " + part
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
