<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;
use SplStack;
use Throwable;

use function array_filter;
use function array_map;
use function array_reverse;
use function array_values;
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
 * A session records at most `$maxRecords` opens, events and rejected-close
 * diagnostics. Beyond that it stops recording rather than growing without
 * bound; the flush then carries one `semantic_logger_truncated` marker.
 *
 * @psalm-import-type DiagnosticData from Types
 * @psalm-import-type DiagnosticCode from Types
 * @psalm-import-type LogRecordList from Types
 * @psalm-import-type LogSessionArray from Types
 * @psalm-import-type OperationIdSet from Types
 * @psalm-import-type SchemaLinks from Types
 * @psalm-import-type TruncationData from Types
 * @psalm-import-type TypeCounts from Types
 */
final class SemanticLogger implements SemanticLoggerInterface, JsonSerializable
{
    /** Ample for a request (tens of records), and small enough that the rendered tree still fits an agent's context. */
    public const DEFAULT_MAX_RECORDS = 1000;

    /** @var LogRecordList Opens, events and closes in record order. */
    private array $records = [];

    /** @var SplStack<OpenEntry> */
    private SplStack $openStack;

    /** @var TypeCounts */
    private array $typeCounts = [];
    private readonly ContextFreezer $contextFreezer;

    /** Opens, events and rejected-close diagnostics counted against the limit. */
    private int $limitedCount = 0;

    /** @var OperationIdSet Opens past the limit: on the stack for LIFO matching, never recorded. */
    private array $unrecordedOpenIds = [];
    private int $dropped = 0;
    private string $firstDroppedType = '';

    /** @param positive-int $maxRecords */
    public function __construct(
        private readonly int $maxRecords = self::DEFAULT_MAX_RECORDS,
    ) {
        /** @var SplStack<OpenEntry> $openStack */
        $openStack = new SplStack();
        $this->openStack = $openStack;
        $this->contextFreezer = new ContextFreezer();
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        $frozen = $this->contextFreezer->freeze($context, 'open');
        $operationId = $this->nextId($frozen['type']);
        $open = new OpenEntry(
            $operationId,
            $frozen['type'],
            $frozen['schemaUrl'],
            $frozen['context'],
            $this->currentOpenId(),
        );
        $this->openStack->push($open);
        if ($this->isFull()) {
            $this->unrecordedOpenIds[$operationId] = true;
            $this->drop($frozen['type']);

            return $operationId;
        }

        $this->limitedCount++;
        $this->records[] = $open;
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
        if ($this->isFull()) {
            $this->drop($frozen['type']);

            return;
        }

        $this->limitedCount++;
        $eventId = $this->nextId($frozen['type']);
        $currentOpenId = $this->currentOpenId();
        $this->records[] = new EventEntry(
            $eventId,
            $frozen['type'],
            $frozen['schemaUrl'],
            $frozen['context'],
            $currentOpenId,
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
        $this->openStack->pop();
        if (isset($this->unrecordedOpenIds[$openId])) {
            unset($this->unrecordedOpenIds[$openId]);
            $this->drop($frozen['type']);

            return;
        }

        // A recorded open always gets its close, even past the limit, so no
        // recorded interval reads as interrupted.
        $closeId = $this->nextId($frozen['type']);
        // A close-time diagnostic belongs inside the scope the close ends.
        if ($frozen['diagnostic'] !== null) {
            $frozen['diagnostic']['relatedId'] = $openId;
            $this->recordDiagnostic($frozen['diagnostic'], $openId);
        }

        $this->records[] = new CloseEntry(
            $closeId,
            $frozen['type'],
            $frozen['schemaUrl'],
            $frozen['context'],
            $openId,
        );
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->snapshot()->toArray();
    }

    /** @return LogSessionArray */
    public function toArray(): array
    {
        return $this->snapshot()->toArray();
    }

    /** @param SchemaLinks $links */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        try {
            if (! $this->openStack->isEmpty()) {
                $this->recordDiagnostic($this->unclosedDiagnostic(), null);
            }

            if ($this->dropped > 0) {
                $this->records[] = $this->truncationEntry($this->nextId(CoreSchema::TRUNCATED_TYPE));
            }

            return new LogJson(CoreSchema::LOG_URL, $this->records, $links);
        } finally {
            $this->resetState();
        }
    }

    /** Non-destructive view of the session; previews the entries flush() would add. */
    private function snapshot(): LogJson
    {
        $records = $this->records;
        if (! $this->openStack->isEmpty()) {
            $records[] = $this->diagnosticEntry(
                $this->unclosedDiagnostic(),
                null,
                $this->previewId(CoreSchema::DIAGNOSTIC_TYPE),
            );
        }

        if ($this->dropped > 0) {
            $records[] = $this->truncationEntry($this->previewId(CoreSchema::TRUNCATED_TYPE));
        }

        return new LogJson(CoreSchema::LOG_URL, $records);
    }

    private function resetState(): void
    {
        /** @var SplStack<OpenEntry> $openStack */
        $openStack = new SplStack();
        $this->openStack = $openStack;
        $this->records = [];
        $this->typeCounts = [];
        $this->limitedCount = 0;
        $this->unrecordedOpenIds = [];
        $this->dropped = 0;
        $this->firstDroppedType = '';
    }

    private function isFull(): bool
    {
        return $this->limitedCount >= $this->maxRecords;
    }

    private function drop(string $type): void
    {
        if ($this->dropped === 0) {
            $this->firstDroppedType = $type;
        }

        $this->dropped++;
    }

    private function truncationEntry(string $id): EventEntry
    {
        /** @var TruncationData $context */
        $context = [
            'limit' => $this->maxRecords,
            'dropped' => $this->dropped,
            'firstDroppedType' => $this->firstDroppedType,
        ];

        return new EventEntry($id, CoreSchema::TRUNCATED_TYPE, CoreSchema::TRUNCATED_URL, $context);
    }

    private function currentOpenId(): string|null
    {
        return $this->openStack->isEmpty() ? null : $this->openStack->top()->id;
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

    /** @param DiagnosticData $diagnostic */
    private function recordDiagnostic(array $diagnostic, string|null $openId): void
    {
        $this->records[] = $this->diagnosticEntry($diagnostic, $openId);
    }

    /** @param DiagnosticData $diagnostic */
    private function diagnosticEntry(array $diagnostic, string|null $openId, string|null $id = null): EventEntry
    {
        return new EventEntry(
            $id ?? $this->nextId(CoreSchema::DIAGNOSTIC_TYPE),
            CoreSchema::DIAGNOSTIC_TYPE,
            CoreSchema::DIAGNOSTIC_URL,
            $diagnostic,
            $openId,
        );
    }

    /** @param DiagnosticCode $code */
    private function recordRejectedClose(
        string $code,
        string $message,
        AbstractContext $context,
        string $attemptedId,
        string|null $currentOpenId,
    ): void {
        if ($this->isFull()) {
            $this->drop(CoreSchema::DIAGNOSTIC_TYPE);

            return;
        }

        $this->limitedCount++;
        $diagnostic = [
            'code' => $code,
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
        $recordedOpens = array_filter(
            array_reverse(iterator_to_array($this->openStack, false)),
            fn (OpenEntry $entry): bool => ! isset($this->unrecordedOpenIds[$entry->id]),
        );

        return [
            'code' => 'unclosed_at_flush',
            'message' => 'Log session ended with unclosed operations.',
            'unclosedIds' => array_values(array_map(
                static fn (OpenEntry $entry): string => $entry->id,
                $recordedOpens,
            )),
        ];
    }
}
