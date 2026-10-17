<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use function count;
use function is_array;
use function is_int;
use function is_object;
use function sprintf;

/**
 * Cross-entry consistency checks for the record-order sequence (`seq`).
 *
 * A document's `seq` values are the only total order over one session: the
 * public tree keeps a scope's own events and its nested child scopes in
 * separate arrays, so without `seq` a reader cannot tell which happened first.
 * That makes the field load-bearing, and a wrong value worse than a missing
 * one — a reader who trusts `seq` would draw the opposite conclusion.
 *
 * The field is optional. A document that carries no `seq` at all is a legacy
 * document and passes untouched; a document that carries it partially is
 * rejected, because a reader cannot mix the two orders.
 */
final class EntrySequenceChecker
{
    /** @var array<int, string> seq value => path of the entry that claimed it */
    private array $owners = [];

    /** @var list<string> */
    private array $missing = [];

    /** @var list<string> */
    private array $violations = [];

    /** @return list<string> Violations found; empty when the sequence is consistent or absent. */
    public function check(object $logData): array
    {
        $this->owners = [];
        $this->missing = [];
        $this->violations = [];

        $this->walkEntryList($logData->open ?? null, 'open', null, null);
        $this->walkEntryList($logData->events ?? null, 'events', null, null);
        $this->walkEntryList($logData->close ?? null, 'close', null, null);

        return [...$this->violations, ...$this->partialSequenceViolations()];
    }

    /**
     * @param mixed       $entries    the raw list from the document
     * @param string      $path       JSON-pointer-ish path of the list
     * @param int|null    $lowerBound seq of the enclosing open, when any
     * @param string|null $scopePath  path of the enclosing open, when any
     */
    private function walkEntryList(mixed $entries, string $path, int|null $lowerBound, string|null $scopePath): void
    {
        if (! is_array($entries)) {
            return;
        }

        $previousPath = null;
        $previousMax = null;
        foreach ($entries as $index => $entry) {
            if (! is_object($entry)) {
                continue;
            }

            $entryPath = sprintf('%s[%d]', $path, $index);
            $this->walkEntry($entry, $entryPath, $lowerBound, $scopePath);
            $this->requireAfterPreviousSibling($entry, $entryPath, $previousPath, $previousMax);
            $previousPath = $entryPath;
            $previousMax = $this->subtreeMaxSeq($entry);
        }
    }

    /**
     * Siblings are listed in record order, so an entry must come after
     * everything under the sibling before it. Equality is left to the
     * duplicate check.
     */
    private function requireAfterPreviousSibling(
        object $entry,
        string $path,
        string|null $previousPath,
        int|null $previousMax,
    ): void {
        $seq = $this->seqOf($entry);
        if ($seq === null || $previousMax === null || $seq >= $previousMax) {
            return;
        }

        $this->violations[] = sprintf(
            '[%s] seq %d must be greater than every seq under the preceding sibling %s (found %d)',
            $path,
            $seq,
            (string) $previousPath,
            $previousMax,
        );
    }

    private function walkEntry(object $entry, string $path, int|null $lowerBound, string|null $scopePath): void
    {
        $seq = $this->claim($entry, $path);
        $this->requireAfterScope($seq, $path, $lowerBound, $scopePath);

        // Descendants of this open must all sit between this open and its close.
        $childBound = $seq ?? $lowerBound;
        $childScope = $seq !== null ? $path : $scopePath;

        $this->walkEntryList($entry->events ?? null, $path . '.events', $childBound, $childScope);
        $this->walkEntryList($entry->open ?? null, $path . '.open', $childBound, $childScope);

        /** @var mixed $close */
        $close = $entry->close ?? null;
        if (is_object($close)) {
            $this->walkClose($close, $path . '.close', $entry, $path);

            return;
        }

        // An orphan close entry may itself nest further closes (internal shape).
        $this->walkEntryList($close, $path . '.close', $childBound, $childScope);
    }

    /** A matched close must be recorded after its own open and after everything inside it. */
    private function walkClose(object $close, string $path, object $open, string $openPath): void
    {
        $seq = $this->claim($close, $path);
        $openSeq = $this->seqOf($open);
        if ($seq === null || $openSeq === null) {
            return;
        }

        if ($seq <= $openSeq) {
            $this->violations[] = sprintf(
                '[%s] Close seq %d must be greater than its open seq %d (%s)',
                $path,
                $seq,
                $openSeq,
                $openPath,
            );
        }

        $maxInside = $this->maxDescendantSeq($open);
        if ($maxInside === null || $seq >= $maxInside) {
            return;
        }

        $this->violations[] = sprintf(
            '[%s] Close seq %d must be greater than every seq nested under %s (found %d)',
            $path,
            $seq,
            $openPath,
            $maxInside,
        );
    }

    private function requireAfterScope(int|null $seq, string $path, int|null $lowerBound, string|null $scopePath): void
    {
        if ($seq === null || $lowerBound === null || $seq > $lowerBound) {
            return;
        }

        $this->violations[] = sprintf(
            '[%s] seq %d must be greater than the enclosing open seq %d (%s)',
            $path,
            $seq,
            $lowerBound,
            (string) $scopePath,
        );
    }

    /** Register this entry's seq, or note its absence. */
    private function claim(object $entry, string $path): int|null
    {
        $seq = $this->seqOf($entry);
        if ($seq === null) {
            if (isset($entry->seq)) {
                $this->violations[] = sprintf('[%s] seq must be an integer >= 1', $path);

                return null;
            }

            $this->missing[] = $path;

            return null;
        }

        $owner = $this->owners[$seq] ?? null;
        if ($owner !== null) {
            $this->violations[] = sprintf('[%s] Duplicate seq %d (already used by %s)', $path, $seq, $owner);

            return $seq;
        }

        $this->owners[$seq] = $path;

        return $seq;
    }

    private function seqOf(object $entry): int|null
    {
        /** @var mixed $seq */
        $seq = $entry->seq ?? null;

        return is_int($seq) && $seq >= 1 ? $seq : null;
    }

    /** Highest seq anywhere under this open, excluding its own matched close. */
    private function maxDescendantSeq(object $open): int|null
    {
        /** @var mixed $close */
        $close = $open->close ?? null;
        $max = null;
        // A list-shaped close holds nested orphan closes (internal shape), not a matched close.
        foreach ([$open->events ?? null, $open->open ?? null, is_array($close) ? $close : null] as $list) {
            if (! is_array($list)) {
                continue;
            }

            /** @var mixed $child */
            foreach ($list as $child) {
                if (! is_object($child)) {
                    continue;
                }

                $max = $this->higher($max, $this->subtreeMaxSeq($child));
            }
        }

        return $max;
    }

    /** Highest seq in this entry's whole subtree, including its own close. */
    private function subtreeMaxSeq(object $entry): int|null
    {
        $max = $this->seqOf($entry);
        /** @var mixed $close */
        $close = $entry->close ?? null;
        if (is_object($close)) {
            $max = $this->higher($max, $this->seqOf($close));
        }

        return $this->higher($max, $this->maxDescendantSeq($entry));
    }

    private function higher(int|null $left, int|null $right): int|null
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return $left > $right ? $left : $right;
    }

    /**
     * Mixed presence is a violation: a reader cannot order entries against
     * siblings that never claimed a position.
     *
     * @return list<string>
     */
    private function partialSequenceViolations(): array
    {
        if ($this->owners === [] || $this->missing === []) {
            return [];
        }

        return [
            sprintf(
                '[%s] seq is missing while %d other entries carry it (a document must use seq for every entry or for none)',
                $this->missing[0],
                count($this->owners),
            ),
        ];
    }
}
