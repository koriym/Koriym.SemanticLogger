<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;
use SplStack;
use Throwable;

use function array_map;
use function array_reverse;
use function iterator_to_array;

/**
 * Semantic operation logger with a single behavior: totality.
 *
 * This logger never throws. Recording failures (a context that cannot be
 * serialized) and protocol misuse (closing without an open, LIFO violations,
 * unclosed operations at flush) are recorded as core-owned diagnostic entries
 * instead of exceptions — a logging failure can never break the application,
 * and "nothing happened" never has to be inferred from absence.
 *
 * @psalm-import-type DiagnosticData from Types
 * @psalm-import-type DiagnosticKind from Types
 * @psalm-import-type EventEntryList from Types
 * @psalm-import-type LogSessionArray from Types
 * @psalm-import-type LogTree from Types
 * @psalm-import-type OpenCloseEntryList from Types
 * @psalm-import-type SchemaLinks from Types
 * @psalm-import-type TypeCounts from Types
 */
final class SemanticLogger implements SemanticLoggerInterface, JsonSerializable
{
    /** @var EventEntryList */
    private array $events = [];

    /** @var SplStack<OpenCloseEntry> */
    private SplStack $openStack;

    /** @var EventEntryList Close entries in chronological close order. */
    private array $closeLog = [];

    /** @var OpenCloseEntryList Completed opens in chronological close order; each carries the parentId captured at open time. */
    private array $completedOperations = [];

    /** @var TypeCounts */
    private array $typeCounts = [];

    /**
     * Record-order counter shared by every entry kind (open, event, close, diagnostic).
     *
     * The per-type id counters cannot order entries of different types, and the
     * public document keeps a scope's events and its nested opens in separate
     * arrays — this counter is the only total order over one session.
     *
     * @var int<0, max>
     */
    private int $sequence = 0;
    private readonly ContextFreezer $contextFreezer;
    private readonly LogTreeBuilder $logTreeBuilder;

    public function __construct()
    {
        /** @var SplStack<OpenCloseEntry> $openStack */
        $openStack = new SplStack();
        $this->openStack = $openStack;
        $this->contextFreezer = new ContextFreezer();
        $this->logTreeBuilder = new LogTreeBuilder();
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        $frozen = $this->contextFreezer->freeze($context, 'open');
        $operationId = $this->nextId($frozen['type']);
        $parentId = $this->openStack->isEmpty() ? null : $this->openStack->top()->id;
        $this->openStack->push(new OpenCloseEntry(
            $operationId,
            $frozen['type'],
            $frozen['schemaUrl'],
            $frozen['context'],
            [],
            $parentId,
            $this->nextSeq(),
        ));
        if ($frozen['diagnostic'] !== null) {
            $frozen['diagnostic']['relatedId'] = $operationId;
            $this->recordDiagnostic($frozen['diagnostic'], $operationId);
        }

        return $operationId;
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
        $frozen = $this->contextFreezer->freeze($context, 'event');
        $eventId = $this->nextId($frozen['type']);
        $currentOpenId = $this->openStack->isEmpty() ? null : $this->openStack->top()->id;
        $this->events[] = new EventEntry(
            $eventId,
            $frozen['type'],
            $frozen['schemaUrl'],
            $frozen['context'],
            $currentOpenId,
            [],
            null,
            $this->nextSeq(),
        );
        if ($frozen['diagnostic'] !== null) {
            $frozen['diagnostic']['relatedId'] = $eventId;
            $this->recordDiagnostic($frozen['diagnostic'], $currentOpenId);
        }
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
        if ($this->openStack->isEmpty()) {
            $this->recordRejectedClose(
                'close_without_open',
                'Cannot close operation: no open operations',
                $context,
                $openId,
                null,
            );

            return;
        }

        $lastOpen = $this->openStack->top();
        if ($lastOpen->id !== $openId) {
            $this->recordRejectedClose(
                'close_id_mismatch',
                "Cannot close operation '{$openId}': expected '{$lastOpen->id}' (LIFO order required)",
                $context,
                $openId,
                $lastOpen->id,
            );

            return;
        }

        $frozen = $this->contextFreezer->freeze($context, 'close');
        $closeId = $this->nextId($frozen['type']);
        $completedOpen = $this->openStack->pop();
        $this->completedOperations[] = $completedOpen;
        $this->closeLog[] = new EventEntry(
            $closeId,
            $frozen['type'],
            $frozen['schemaUrl'],
            $frozen['context'],
            $openId,
            [],
            null,
            $this->nextSeq(),
        );
        if ($frozen['diagnostic'] !== null) {
            $frozen['diagnostic']['relatedId'] = $openId;
            $this->recordDiagnostic($frozen['diagnostic'], $openId);
        }
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->createLogJson()->toArray();
    }

    /** @return LogSessionArray */
    public function toArray(): array
    {
        return $this->createLogJson()->toArray();
    }

    /** @param SchemaLinks $links */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        try {
            if (! $this->hasSession()) {
                return $this->emptyLog($links);
            }

            if ($this->openStack->isEmpty()) {
                return $this->buildCompletedLog($links);
            }

            $this->recordDiagnostic($this->unclosedDiagnostic(), null);

            return $this->buildLiveLog($links);
        } finally {
            $this->resetState();
        }
    }

    private function createLogJson(): LogJson
    {
        if (! $this->hasSession()) {
            return $this->emptyLog();
        }

        if ($this->openStack->isEmpty()) {
            return $this->buildCompletedLog();
        }

        // Non-destructive preview of the unclosed diagnostic for snapshots.
        $diagnostic = $this->diagnosticEntry(
            $this->unclosedDiagnostic(),
            null,
            $this->previewId(CoreSchema::DIAGNOSTIC_TYPE),
        );
        $events = $this->events;
        $events[] = $diagnostic;

        return $this->buildLiveLog([], $events);
    }

    /**
     * @param SchemaLinks    $links
     * @param EventEntryList $events
     */
    private function buildCompletedLog(array $links = [], array|null $events = null): LogJson
    {
        $tree = $this->logTreeBuilder->completed($this->completedOperations, $this->closeLog);

        return $this->logFromTree($tree, $links, $events);
    }

    /**
     * @param SchemaLinks    $links
     * @param EventEntryList $events
     */
    private function buildLiveLog(array $links = [], array|null $events = null): LogJson
    {
        $tree = $this->logTreeBuilder->includingLive($this->completedOperations, $this->openStack, $this->closeLog);

        return $this->logFromTree($tree, $links, $events);
    }

    /**
     * @param LogTree        $tree
     * @param SchemaLinks    $links
     * @param EventEntryList $events
     */
    private function logFromTree(array $tree, array $links, array|null $events): LogJson
    {
        return new LogJson(
            CoreSchema::LOG_URL,
            $tree['open'],
            $tree['close'],
            $events ?? $this->events,
            $links,
        );
    }

    /** @param SchemaLinks $links */
    private function emptyLog(array $links = []): LogJson
    {
        return new LogJson(CoreSchema::LOG_URL, [], [], [], $links);
    }

    private function hasSession(): bool
    {
        return $this->completedOperations !== []
            || ! $this->openStack->isEmpty()
            || $this->events !== []
            || $this->closeLog !== [];
    }

    private function resetState(): void
    {
        /** @var SplStack<OpenCloseEntry> $openStack */
        $openStack = new SplStack();
        $this->openStack = $openStack;
        $this->closeLog = [];
        $this->completedOperations = [];
        $this->events = [];
        $this->typeCounts = [];
        $this->sequence = 0;
    }

    private function nextId(string $type): string
    {
        $this->typeCounts[$type] = ($this->typeCounts[$type] ?? 0) + 1;

        return $type . '_' . $this->typeCounts[$type];
    }

    private function previewId(string $type): string
    {
        return $type . '_' . (($this->typeCounts[$type] ?? 0) + 1);
    }

    /**
     * Next position in the session's record-order sequence; first entry of a session is 1.
     *
     * @return positive-int
     */
    private function nextSeq(): int
    {
        return ++$this->sequence;
    }

    /**
     * The seq a preview-only entry would take, without consuming it.
     *
     * @return positive-int
     */
    private function previewSeq(): int
    {
        return $this->sequence + 1;
    }

    /** @param DiagnosticData $diagnostic */
    private function recordDiagnostic(array $diagnostic, string|null $openId): void
    {
        $this->events[] = $this->diagnosticEntry($diagnostic, $openId);
    }

    /** @param DiagnosticData $diagnostic */
    private function diagnosticEntry(array $diagnostic, string|null $openId, string|null $id = null): EventEntry
    {
        // The preview path ($id given) must not consume either counter.
        return new EventEntry(
            $id ?? $this->nextId(CoreSchema::DIAGNOSTIC_TYPE),
            CoreSchema::DIAGNOSTIC_TYPE,
            CoreSchema::DIAGNOSTIC_URL,
            $diagnostic,
            $openId,
            [],
            null,
            $id === null ? $this->nextSeq() : $this->previewSeq(),
        );
    }

    /** @param DiagnosticKind $kind */
    private function recordRejectedClose(
        string $kind,
        string $message,
        AbstractContext $context,
        string $attemptedId,
        string|null $currentOpenId,
    ): void {
        $diagnostic = [
            'kind' => $kind,
            'message' => $message,
            'relatedId' => $attemptedId,
        ];

        try {
            $diagnostic['discardedContext'] = $this->contextFreezer->freezeData($context);
        } catch (Throwable $throwable) {
            $diagnostic['exceptionClass'] = $throwable::class;
        }

        $this->recordDiagnostic($diagnostic, $currentOpenId);
    }

    /** @return DiagnosticData */
    private function unclosedDiagnostic(): array
    {
        $ids = array_map(
            static fn (OpenCloseEntry $entry): string => $entry->id,
            array_reverse(iterator_to_array($this->openStack, false)),
        );

        return [
            'kind' => 'unclosed_at_flush',
            'message' => 'Log session ended with unclosed operations.',
            'unclosedIds' => $ids,
        ];
    }
}
