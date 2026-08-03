<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Koriym\SemanticLogger\Exception\InvalidContextTypeException;
use Koriym\SemanticLogger\Exception\InvalidOperationOrderException;
use Koriym\SemanticLogger\Exception\InvalidSchemaUrlException;
use Koriym\SemanticLogger\Exception\NoLogSessionException;
use Koriym\SemanticLogger\Exception\NoOpenOperationsException;
use Koriym\SemanticLogger\Exception\UnclosedLogicException;
use Override;
use SplStack;
use stdClass;
use Throwable;

use function array_combine;
use function array_keys;
use function array_map;
use function array_reverse;
use function array_values;
use function get_debug_type;
use function get_object_vars;
use function is_array;
use function is_string;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function strval;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_SCHEME;

/**
 * @psalm-import-type ContextData from Types
 * @psalm-import-type DiagnosticData from Types
 * @psalm-import-type DiagnosticKind from Types
 * @psalm-import-type EventEntryList from Types
 * @psalm-import-type LogSessionArray from Types
 * @psalm-import-type OpenChildrenByParent from Types
 * @psalm-import-type OpenCloseEntryList from Types
 * @psalm-import-type PreparedContext from Types
 * @psalm-import-type SchemaLinks from Types
 * @psalm-import-type TypeCounts from Types
 */
final class SemanticLogger implements SemanticLoggerInterface, JsonSerializable
{
    private const SEMANTIC_LOG_SCHEMA_URL = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';
    private const DIAGNOSTIC_TYPE = 'semantic_logger_error';
    private const DIAGNOSTIC_SCHEMA_URL = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-logger-error.json';
    private const INVALID_CONTEXT_TYPE = 'semantic_logger_invalid_context';
    private const INVALID_CONTEXT_SCHEMA_URL = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-logger-invalid-context.json';
    private const RESERVED_TYPE_PREFIX = 'semantic_logger_';
    private const TYPE_PATTERN = '/^[a-z_]+$/D';
    private const RELATIVE_SCHEMA_PATTERN = '/^\.\/schemas\/[a-zA-Z0-9_-]+\.json$/D';
    private const URI_SCHEME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9+.-]*$/D';

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

    public function __construct(
        private readonly SemanticLoggerMode $mode = SemanticLoggerMode::Strict,
    ) {
        /** @var SplStack<OpenCloseEntry> $openStack */
        $openStack = new SplStack();
        $this->openStack = $openStack;
    }

    #[Override]
    public function open(AbstractContext $context): string
    {
        $prepared = $this->prepareContext($context, 'open');
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
        $prepared = $this->prepareContext($context, 'event');
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

        $prepared = $this->prepareContext($context, 'close');
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
                return $this->buildLogJson($links);
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

            return $this->buildLogJson($links, true);
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
            return $this->buildLogJson();
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
            $this->previewId(self::DIAGNOSTIC_TYPE),
        );
        $events = $this->events;
        $events[] = $diagnostic;

        return $this->buildLogJson([], true, $events);
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
     * @return OpenCloseEntryList
     */
    private function buildNestedOpen(bool $includeLive = false): array
    {
        $childrenByParent = $this->groupByParent($this->allOpenOperations($includeLive));

        return $this->buildOpenChildren(null, $childrenByParent);
    }

    /**
     * @param OpenChildrenByParent $childrenByParent
     *
     * @return OpenCloseEntryList
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
     * Build the internal close tree used for profile attachment and tree serialization.
     *
     * Closes are still tracked by openId internally even though the public JSON
     * serializer nests matched closes directly under their open node.
     *
     * @return EventEntryList
     */
    private function buildNestedClose(bool $includeLive = false): array
    {
        $childrenByParent = $this->groupByParent($this->allOpenOperations($includeLive));
        $closeByOpenId = [];
        foreach ($this->closeLog as $close) {
            if ($close->openId !== null) {
                $closeByOpenId[$close->openId] = $close;
            }
        }

        return $this->buildCloseChildren(null, $childrenByParent, $closeByOpenId);
    }

    /**
     * @param OpenChildrenByParent      $childrenByParent
     * @param array<string, EventEntry> $closeByOpenId
     *
     * @return EventEntryList
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
            $childCloses = $this->buildCloseChildren($op->id, $childrenByParent, $closeByOpenId);
            if ($close === null) {
                foreach ($childCloses as $childClose) {
                    $result[] = $childClose;
                }

                continue;
            }

            $result[] = new EventEntry(
                $close->id,
                $close->type,
                $close->schemaUrl,
                $close->context,
                $close->openId,
                $childCloses,
                $close->profile,
            );
        }

        return $result;
    }

    /**
     * @param OpenCloseEntryList $operations
     *
     * @return OpenChildrenByParent
     */
    private function groupByParent(array $operations): array
    {
        $childrenByParent = [];
        foreach ($operations as $op) {
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
     * @return ContextData
     */
    private function contextToArray(AbstractContext $context): array
    {
        if ($context instanceof JsonSerializable) {
            /** @var mixed $serialized */
            $serialized = $context->jsonSerialize();
            if (is_array($serialized) && $this->hasOnlyStringKeys($serialized)) {
                return $this->freezeContext($serialized);
            }
        }

        /** @var ContextData $mixedArray */
        $mixedArray = (array) $context;

        return $this->freezeContext($mixedArray);
    }

    /** @param array<mixed> $context */
    private function hasOnlyStringKeys(array $context): bool
    {
        foreach (array_keys($context) as $key) {
            if (! is_string($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Freeze user values as an inert JSON tree so later snapshots and flushes
     * never invoke nested JsonSerializable objects a second time.
     *
     * @param array<mixed> $context
     *
     * @return ContextData
     */
    private function freezeContext(array $context): array
    {
        $json = json_encode($context, JSON_THROW_ON_ERROR);

        return $this->contextFromDecoded(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /** @return ContextData */
    private function contextFromDecoded(mixed $frozen): array
    {
        if (is_array($frozen)) {
            // Only an empty PHP context reaches this JSON-list branch;
            // non-empty lists are rejected before context freezing.
            return [];
        }

        if ($frozen instanceof stdClass) {
            $properties = get_object_vars($frozen);
            $keys = array_map(
                static fn (int|string $key): string => strval($key),
                array_keys($properties),
            );

            return array_combine($keys, array_values($properties));
        }

        return [];
    }

    /**
     * @param SchemaLinks    $links
     * @param EventEntryList $events
     */
    private function buildLogJson(array $links = [], bool $includeLive = false, array|null $events = null): LogJson
    {
        return new LogJson(
            self::SEMANTIC_LOG_SCHEMA_URL,
            $this->buildNestedOpen($includeLive),
            $this->buildNestedClose($includeLive),
            $events ?? $this->events,
            $links,
        );
    }

    /** @param SchemaLinks $links */
    private function emptyLog(array $links = []): LogJson
    {
        return new LogJson(self::SEMANTIC_LOG_SCHEMA_URL, [], [], [], $links);
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

    /** @return OpenCloseEntryList */
    private function allOpenOperations(bool $includeLive): array
    {
        $operations = $this->completedOperations;
        if (! $includeLive) {
            return $operations;
        }

        /** @var list<OpenCloseEntry> $liveOperations */
        $liveOperations = array_reverse(iterator_to_array($this->openStack, false));
        foreach ($liveOperations as $operation) {
            $operations[] = $operation;
        }

        return $operations;
    }

    /** @return PreparedContext */
    private function prepareContext(AbstractContext $context, string $operation): array
    {
        /** @var mixed $rawType */
        $rawType = $context::TYPE;
        /** @var mixed $rawSchemaUrl */
        $rawSchemaUrl = $context::SCHEMA_URL;
        /** @var list<DiagnosticData> $diagnostics */
        $diagnostics = [];
        $type = is_string($rawType) && $this->isValidType($rawType) ? $rawType : null;
        $schemaUrl = is_string($rawSchemaUrl) && $this->isValidSchemaUrl($rawSchemaUrl) ? $rawSchemaUrl : null;

        if ($type === null) {
            if ($this->mode === SemanticLoggerMode::Strict) {
                throw new InvalidContextTypeException();
            }

            $diagnostics[] = [
                'kind' => 'invalid_type',
                'message' => 'Context TYPE must match ^[a-z_]+$ and must not use the semantic_logger_* namespace.',
                'originalType' => $this->originalValue($rawType),
            ];
        }

        if ($schemaUrl === null) {
            if ($this->mode === SemanticLoggerMode::Strict) {
                throw new InvalidSchemaUrlException();
            }

            $diagnostics[] = [
                'kind' => 'invalid_schema_url',
                'message' => 'Context SCHEMA_URL must be an absolute URI or ./schemas/<name>.json.',
                'originalSchemaUrl' => $this->originalValue($rawSchemaUrl),
            ];
        }

        $serialized = [];
        $serializationSucceeded = false;
        try {
            $serialized = $this->contextToArray($context);
            $serializationSucceeded = true;
        } catch (Throwable $throwable) {
            if ($this->mode === SemanticLoggerMode::Strict) {
                throw $throwable;
            }

            $diagnostics[] = [
                'kind' => 'context_serialization_failed',
                'message' => 'Context serialization failed.',
                'exceptionClass' => $throwable::class,
            ];
        }

        if ($diagnostics === [] && $type !== null && $schemaUrl !== null) {
            return [
                'type' => $type,
                'schemaUrl' => $schemaUrl,
                'context' => $serialized,
                'diagnostics' => [],
            ];
        }

        if ($serializationSucceeded) {
            $diagnostics = array_map(static function (array $diagnostic) use ($serialized): array {
                $diagnostic['discardedContext'] = $serialized;

                return $diagnostic;
            }, $diagnostics);
        }

        return [
            'type' => self::INVALID_CONTEXT_TYPE,
            'schemaUrl' => self::INVALID_CONTEXT_SCHEMA_URL,
            'context' => $this->placeholderContext($operation, $diagnostics),
            'diagnostics' => $diagnostics,
        ];
    }

    private function isValidType(mixed $type): bool
    {
        if (! is_string($type) || preg_match(self::TYPE_PATTERN, $type) !== 1) {
            return false;
        }

        return ! str_starts_with($type, self::RESERVED_TYPE_PREFIX);
    }

    private function isValidSchemaUrl(mixed $schemaUrl): bool
    {
        if (! is_string($schemaUrl) || $schemaUrl === '') {
            return false;
        }

        if (preg_match(self::RELATIVE_SCHEMA_PATTERN, $schemaUrl) === 1) {
            return true;
        }

        $scheme = parse_url($schemaUrl, PHP_URL_SCHEME);

        return is_string($scheme) && preg_match(self::URI_SCHEME_PATTERN, $scheme) === 1;
    }

    /**
     * @param list<DiagnosticData> $diagnostics
     *
     * @return ContextData
     */
    private function placeholderContext(string $operation, array $diagnostics): array
    {
        $errors = array_map(
            static fn (array $diagnostic): array => [
                'kind' => $diagnostic['kind'],
                'message' => $diagnostic['message'],
            ],
            $diagnostics,
        );
        $context = [
            'operation' => $operation,
            'errors' => $errors,
        ];

        foreach ($diagnostics as $diagnostic) {
            if (isset($diagnostic['originalType'])) {
                $context['originalType'] = $diagnostic['originalType'];
            }

            if (isset($diagnostic['originalSchemaUrl'])) {
                $context['originalSchemaUrl'] = $diagnostic['originalSchemaUrl'];
            }
        }

        return $context;
    }

    private function originalValue(mixed $value): string
    {
        return is_string($value) ? $value : get_debug_type($value);
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
            $id ?? $this->nextId(self::DIAGNOSTIC_TYPE),
            self::DIAGNOSTIC_TYPE,
            self::DIAGNOSTIC_SCHEMA_URL,
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
            $diagnostic['discardedContext'] = $this->contextToArray($context);
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
