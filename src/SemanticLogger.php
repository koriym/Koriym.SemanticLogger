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

use function assert;
use function is_array;
use function is_string;

final class SemanticLogger implements SemanticLoggerInterface, JsonSerializable
{
    private const SEMANTIC_LOG_SCHEMA_URL = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/combined.json';

    /** @var list<EventEntry> */
    private array $events = [];

    /** @var SplStack<OpenCloseEntry> */
    private SplStack $openStack;

    /** @var list<EventEntry> Close entries in chronological close order. */
    private array $closeLog = [];

    /** @var list<OpenCloseEntry> Completed opens in chronological close order; each carries the parentId captured at open time. */
    private array $completedOperations = [];

    /** @var array<string, int> */
    private array $typeCounts = [];

    public function __construct()
    {
        /** @var SplStack<OpenCloseEntry> $openStack */
        $openStack = new SplStack();
        $this->openStack = $openStack;
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        $type = $context::TYPE;
        assert(is_string($type));
        $this->typeCounts[$type] = ($this->typeCounts[$type] ?? 0) + 1;
        $operationId = "{$type}_{$this->typeCounts[$type]}";

        $schemaUrl = $context::SCHEMA_URL;
        assert(is_string($schemaUrl));

        $parentId = $this->openStack->isEmpty() ? null : $this->openStack->top()->id;

        $contextArray = $this->contextToArray($context);
        $this->openStack->push(new OpenCloseEntry(
            $operationId,
            $type,
            $schemaUrl,
            $contextArray,
            [],
            $parentId,
        ));

        return $operationId;
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
        $type = $context::TYPE;
        assert(is_string($type));
        $this->typeCounts[$type] = ($this->typeCounts[$type] ?? 0) + 1;
        $eventId = "{$type}_{$this->typeCounts[$type]}";

        $schemaUrl = $context::SCHEMA_URL;
        assert(is_string($schemaUrl));

        $contextArray = $this->contextToArray($context);

        // Get current open operation ID for correlation
        $currentOpenId = $this->openStack->isEmpty() ? null : $this->openStack->top()->id;

        $this->events[] = new EventEntry(
            $eventId,
            $type,
            $schemaUrl,
            $contextArray,
            $currentOpenId,
        );
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
        if ($this->openStack->isEmpty()) {
            throw new NoOpenOperationsException();
        }

        $lastOpen = $this->openStack->top();
        if ($lastOpen->id !== $openId) {
            throw new InvalidOperationOrderException($openId, $lastOpen->id);
        }

        $completedOpen = $this->openStack->pop();
        $this->completedOperations[] = $completedOpen;

        $type = $context::TYPE;
        assert(is_string($type));
        $this->typeCounts[$type] = ($this->typeCounts[$type] ?? 0) + 1;
        $closeId = "{$type}_{$this->typeCounts[$type]}";

        $schemaUrl = $context::SCHEMA_URL;
        assert(is_string($schemaUrl));

        $contextArray = $this->contextToArray($context);
        $this->closeLog[] = new EventEntry(
            $closeId,
            $type,
            $schemaUrl,
            $contextArray,
            $openId,
        );
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->createLogJson()->toArray();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->createLogJson()->toArray();
    }

    /** @param list<array{rel: string, href: string, title?: string, type?: string}> $links */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        // Check for any operations at all
        if (empty($this->completedOperations) && $this->openStack->isEmpty()) {
            throw new NoLogSessionException('no open entry');
        }

        // Detect unclosed operations - this is a programming error
        if (! $this->openStack->isEmpty()) {
            $lastOpen = $this->getLastOpenContext();

            throw new UnclosedLogicException(
                $this->openStack->count(),
                $lastOpen->type,
                $lastOpen->schemaUrl,
            );
        }

        $logJson = new LogJson(
            self::SEMANTIC_LOG_SCHEMA_URL,
            $this->buildNestedOpen(),
            $this->buildNestedClose(),
            $this->events,
            $links,
        );

        // Clear internal state
        /** @var SplStack<OpenCloseEntry> $openStack */
        $openStack = new SplStack();
        $this->openStack = $openStack;
        $this->closeLog = [];
        $this->completedOperations = [];
        $this->events = [];
        $this->typeCounts = [];

        return $logJson;
    }

    private function createLogJson(): LogJson
    {
        if (empty($this->completedOperations) && $this->openStack->isEmpty()) {
            throw new NoLogSessionException('no open entry');
        }

        return new LogJson(
            self::SEMANTIC_LOG_SCHEMA_URL,
            $this->buildNestedOpen(),
            $this->buildNestedClose(),
            $this->events,
        );
    }

    private function getLastOpenContext(): OpenCloseEntry
    {
        $stack = clone $this->openStack;

        return $stack->top();
    }

    /**
     * Build the open tree from real parent-child links captured at open() time.
     *
     * Each completed operation carries its own parentId; children are grouped
     * under their parent and appear in chronological close order (which equals
     * open order for correctly-nested LIFO sessions).
     *
     * @return list<OpenCloseEntry>
     */
    private function buildNestedOpen(): array
    {
        $childrenByParent = $this->groupByParent();

        return $this->buildOpenChildren(null, $childrenByParent);
    }

    /**
     * @param array<string, list<OpenCloseEntry>> $childrenByParent
     *
     * @return list<OpenCloseEntry>
     */
    private function buildOpenChildren(string|null $parentId, array $childrenByParent): array
    {
        $key = $parentId ?? '';
        if (! isset($childrenByParent[$key])) {
            return [];
        }

        $result = [];
        foreach ($childrenByParent[$key] as $op) {
            $result[] = new OpenCloseEntry(
                $op->id,
                $op->type,
                $op->schemaUrl,
                $op->context,
                $this->buildOpenChildren($op->id, $childrenByParent),
                $op->parentId,
            );
        }

        return $result;
    }

    /**
     * Build the close tree that mirrors the open tree's shape but carries close contexts.
     *
     * Each close is paired to its open via openId; child-close ordering follows
     * the open tree exactly so the two trees stay structurally parallel.
     *
     * @return list<EventEntry>
     */
    private function buildNestedClose(): array
    {
        $childrenByParent = $this->groupByParent();
        $closeByOpenId = [];
        foreach ($this->closeLog as $close) {
            if ($close->openId !== null) {
                $closeByOpenId[$close->openId] = $close;
            }
        }

        return $this->buildCloseChildren(null, $childrenByParent, $closeByOpenId);
    }

    /**
     * @param array<string, list<OpenCloseEntry>> $childrenByParent
     * @param array<string, EventEntry>           $closeByOpenId
     *
     * @return list<EventEntry>
     */
    private function buildCloseChildren(string|null $parentId, array $childrenByParent, array $closeByOpenId): array
    {
        $key = $parentId ?? '';
        if (! isset($childrenByParent[$key])) {
            return [];
        }

        $result = [];
        foreach ($childrenByParent[$key] as $op) {
            $close = $closeByOpenId[$op->id] ?? null;
            if ($close === null) {
                continue;
            }

            $result[] = new EventEntry(
                $close->id,
                $close->type,
                $close->schemaUrl,
                $close->context,
                $close->openId,
                $this->buildCloseChildren($op->id, $childrenByParent, $closeByOpenId),
                $close->profile,
            );
        }

        return $result;
    }

    /** @return array<string, list<OpenCloseEntry>> */
    private function groupByParent(): array
    {
        $childrenByParent = [];
        foreach ($this->completedOperations as $op) {
            $key = $op->parentId ?? '';
            $childrenByParent[$key][] = $op;
        }

        return $childrenByParent;
    }

    /**
     * Convert a context object to its stored array shape.
     *
     * Prefers `JsonSerializable::jsonSerialize()` when the context implements it,
     * so callers that need an editorial shape (e.g. emitting empty maps as stdClass
     * to satisfy `{}` schemas) retain control. Falls back to `(array)` cast for
     * legacy contexts that rely on public-property introspection.
     *
     * @return array<string, mixed>
     */
    private function contextToArray(AbstractContext $context): array
    {
        if ($context instanceof JsonSerializable) {
            /** @var mixed $serialized */
            $serialized = $context->jsonSerialize();
            if (is_array($serialized)) {
                /** @var array<string, mixed> $serialized */
                return $serialized;
            }
        }

        /** @var array<string, mixed> $mixedArray */
        $mixedArray = (array) $context;

        return $mixedArray;
    }
}
