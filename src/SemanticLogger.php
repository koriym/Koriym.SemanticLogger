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

use function array_pop;
use function array_reverse;
use function assert;
use function is_string;

final class SemanticLogger implements SemanticLoggerInterface, JsonSerializable
{
    private const SEMANTIC_LOG_SCHEMA_URL = 'https://koriym.github.io/semantic-logger/schemas/semantic-log.json';

    /** @var list<EventEntry> */
    private array $events = [];

    /** @var SplStack<OpenCloseEntry> */
    private SplStack $openStack;

    /** @var SplStack<EventEntry> */
    private SplStack $closeStack;

    /** @var array<OpenCloseEntry> */
    private array $completedOperations = [];

    /** @var array<string, int> */
    private array $typeCounts = [];

    public function __construct()
    {
        /** @var SplStack<OpenCloseEntry> $openStack */
        $openStack = new SplStack();
        $this->openStack = $openStack;
        /** @var SplStack<EventEntry> $closeStack */
        $closeStack = new SplStack();
        $this->closeStack = $closeStack;
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

        $contextArray = $this->contextToArray($context);
        $this->openStack->push(new OpenCloseEntry(
            $operationId,
            $type,
            $schemaUrl,
            $contextArray,
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
        $this->closeStack->push(new EventEntry(
            $closeId,
            $type,
            $schemaUrl,
            $contextArray,
            $openId,
        ));
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

    /** @param list<array{rel: string, href: string, title?: string, type?: string}> $relations */
    #[Override]
    public function flush(array $relations = []): LogJson
    {
        // Check for any operations at all
        if (! empty($this->completedOperations) || ! $this->openStack->isEmpty()) {
            // Detect unclosed operations - this is a programming error
            if (! $this->openStack->isEmpty()) {
                $lastOpen = $this->getLastOpenContext();

                throw new UnclosedLogicException(
                    $this->openStack->count(),
                    $lastOpen->type,
                    $lastOpen->schemaUrl,
                );
            }
        } else {
            throw new NoLogSessionException('no open entry');
        }


        $logJson = new LogJson(
            self::SEMANTIC_LOG_SCHEMA_URL,
            $this->buildNestedOpen(),
            $this->events,
            $this->buildNestedClose(),
            $relations,
        );

        // Clear internal state
        /** @var SplStack<OpenCloseEntry> $openStack */
        $openStack = new SplStack();
        $this->openStack = $openStack;
        /** @var SplStack<EventEntry> $closeStack */
        $closeStack = new SplStack();
        $this->closeStack = $closeStack;
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
            $this->events,
            $this->buildNestedClose(),
        );
    }

    private function getLastOpenContext(): OpenCloseEntry
    {
        $stack = clone $this->openStack;

        return $stack->top();
    }

    private function buildNestedOpen(): OpenCloseEntry
    {
        // Rebuild the nested structure from completed operations
        $operations = array_reverse($this->completedOperations);
        $result = array_pop($operations);

        while (! empty($operations)) {
            $parent = array_pop($operations);
            $result = new OpenCloseEntry(
                $parent->id,
                $parent->type,
                $parent->schemaUrl,
                $parent->context,
                $result,
            );
        }

        return $result;
    }

    private function buildNestedClose(): EventEntry
    {
        // Convert stack to array and reverse to get correct order
        $closeEntries = [];
        $stack = clone $this->closeStack;
        while (! $stack->isEmpty()) {
            $closeEntries[] = $stack->pop();
        }

        $closeEntries = array_reverse($closeEntries);

        // Build nested structure from outermost to innermost
        $result = array_pop($closeEntries);

        while (! empty($closeEntries)) {
            $child = array_pop($closeEntries);
            // $child cannot be null since we checked ! empty($closeEntries)
            /** @var EventEntry $child */
            $result = new EventEntry(
                $result->id,
                $result->type,
                $result->schemaUrl,
                $result->context,
                $result->openId,
                $child,
            );
        }

        return $result;
    }

    /**
     * Convert context object to typed array
     *
     * @return array<string, mixed>
     */
    private function contextToArray(AbstractContext $context): array
    {
        /** @var array<string, mixed> $mixedArray */
        $mixedArray = (array) $context;

        return $mixedArray;
    }
}
