<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Koriym\SemanticLogger\Exception\InvalidOperationOrderException;
use Koriym\SemanticLogger\Exception\NoLogSessionException;
use Koriym\SemanticLogger\Exception\NoOpenOperationsException;
use Koriym\SemanticLogger\Exception\UnclosedLogicException;
use Override;
use SplStack;
use Throwable;

use function array_map;
use function array_reverse;
use function iterator_to_array;

/**
 * @psalm-import-type DiagnosticData from Types
 * @psalm-import-type DiagnosticKind from Types
 * @psalm-import-type EventEntryList from Types
 * @psalm-import-type LogTree from Types
 * @psalm-import-type LogSessionArray from Types
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
    private readonly ContextPreparer $contextPreparer;
    private readonly LogTreeBuilder $logTreeBuilder;

    public function __construct(
        private readonly SemanticLoggerMode $mode = SemanticLoggerMode::Strict,
    ) {
        /** @var SplStack<OpenCloseEntry> $openStack */
        $openStack = new SplStack();
        $this->openStack = $openStack;
        $this->contextPreparer = new ContextPreparer($mode);
        $this->logTreeBuilder = new LogTreeBuilder();
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        $prepared = $this->contextPreparer->prepare($context, 'open');
        $operationId = $this->nextId($prepared['type']);
        $parentId = $this->openStack->isEmpty() ? null : $this->openStack->top()->id;
        $this->openStack->push(new OpenCloseEntry(
            $operationId,
            $prepared['type'],
            $prepared['schemaUrl'],
            $prepared['context'],
            [],
            $parentId,
        ));
        $this->recordDiagnostics($this->withRelatedId($prepared['diagnostics'], $operationId), $operationId);

        return $operationId;
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
        $prepared = $this->contextPreparer->prepare($context, 'event');
        $eventId = $this->nextId($prepared['type']);
        $currentOpenId = $this->openStack->isEmpty() ? null : $this->openStack->top()->id;
        $this->events[] = new EventEntry(
            $eventId,
            $prepared['type'],
            $prepared['schemaUrl'],
            $prepared['context'],
            $currentOpenId,
        );
        $this->recordDiagnostics($this->withRelatedId($prepared['diagnostics'], $eventId), $currentOpenId);
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
        if ($this->openStack->isEmpty()) {
            if ($this->mode === SemanticLoggerMode::Total) {
                $this->recordRejectedClose('close_without_open', 'Cannot close operation: no open operations', $context, $openId, null);

                return;
            }

            throw new NoOpenOperationsException();
        }

        $lastOpen = $this->openStack->top();
        if ($lastOpen->id !== $openId) {
            if ($this->mode === SemanticLoggerMode::Total) {
                $this->recordRejectedClose(
                    'close_id_mismatch',
                    "Cannot close operation '{$openId}': expected '{$lastOpen->id}' (LIFO order required)",
                    $context,
                    $openId,
                    $lastOpen->id,
                );

                return;
            }

            throw new InvalidOperationOrderException($openId, $lastOpen->id);
        }

        $prepared = $this->contextPreparer->prepare($context, 'close');
        $closeId = $this->nextId($prepared['type']);
        $completedOpen = $this->openStack->pop();
        $this->completedOperations[] = $completedOpen;
        $this->closeLog[] = new EventEntry(
            $closeId,
            $prepared['type'],
            $prepared['schemaUrl'],
            $prepared['context'],
            $openId,
        );
        $this->recordDiagnostics($this->withRelatedId($prepared['diagnostics'], $openId), $openId);
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
                if ($this->mode === SemanticLoggerMode::Total) {
                    return $this->emptyLog($links);
                }

                throw new NoLogSessionException('no open entry');
            }

            if ($this->openStack->isEmpty()) {
                return $this->buildCompletedLog($links);
            }

            if ($this->mode === SemanticLoggerMode::Strict) {
                $lastOpen = $this->getLastOpenContext();

                throw new UnclosedLogicException(
                    $this->openStack->count(),
                    $lastOpen->type,
                    $lastOpen->schemaUrl,
                );
            }

            $this->recordDiagnostics([$this->unclosedDiagnostic()], null);

            return $this->buildLiveLog($links);
        } finally {
            $this->resetState();
        }
    }

    private function createLogJson(): LogJson
    {
        if (! $this->hasSession()) {
            if ($this->mode === SemanticLoggerMode::Total) {
                return $this->emptyLog();
            }

            throw new NoLogSessionException('no open entry');
        }

        if ($this->openStack->isEmpty()) {
            return $this->buildCompletedLog();
        }

        if ($this->mode === SemanticLoggerMode::Strict) {
            $lastOpen = $this->getLastOpenContext();

            throw new UnclosedLogicException(
                $this->openStack->count(),
                $lastOpen->type,
                $lastOpen->schemaUrl,
            );
        }

        $diagnostic = $this->diagnosticEntry(
            $this->unclosedDiagnostic(),
            null,
            $this->previewId(CoreSchema::DIAGNOSTIC_TYPE),
        );
        $events = $this->events;
        $events[] = $diagnostic;

        return $this->buildLiveLog([], $events);
    }

    private function getLastOpenContext(): OpenCloseEntry
    {
        $stack = clone $this->openStack;

        return $stack->top();
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
            $this->mode,
        );
    }

    /** @param SchemaLinks $links */
    private function emptyLog(array $links = []): LogJson
    {
        return new LogJson(CoreSchema::LOG_URL, [], [], [], $links, $this->mode);
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
     * @param list<DiagnosticData> $diagnostics
     *
     * @return list<DiagnosticData>
     */
    private function withRelatedId(array $diagnostics, string $relatedId): array
    {
        return array_map(static function (array $diagnostic) use ($relatedId): array {
            $diagnostic['relatedId'] = $relatedId;

            return $diagnostic;
        }, $diagnostics);
    }

    /** @param list<DiagnosticData> $diagnostics */
    private function recordDiagnostics(array $diagnostics, string|null $openId): void
    {
        foreach ($diagnostics as $diagnostic) {
            $this->events[] = $this->diagnosticEntry($diagnostic, $openId);
        }
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
            $diagnostic['discardedContext'] = $this->contextPreparer->freezeContext($context);
        } catch (Throwable $throwable) {
            $diagnostic['exceptionClass'] = $throwable::class;
        }

        $this->recordDiagnostics([$diagnostic], $currentOpenId);
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
