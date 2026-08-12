<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use stdClass;
use Throwable;

use function array_combine;
use function array_keys;
use function array_map;
use function array_values;
use function get_debug_type;
use function get_object_vars;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strval;

use const JSON_THROW_ON_ERROR;

/**
 * Freezes user contexts into inert JSON trees at record time.
 *
 * The freeze is a correctness mechanism, not failure absorption: it deep-copies
 * the context through a JSON round-trip so later mutation or nested
 * JsonSerializable re-invocation can never alter a recorded entry, and so a
 * serialization failure surfaces at the operation it belongs to instead of at
 * flush time. When serialization fails, the context is replaced by a core-owned
 * placeholder that preserves the entry's position in the tree — the loss radius
 * of one bad context is that entry, never the session.
 *
 * @psalm-import-type ContextData from Types
 * @psalm-import-type DiagnosticData from Types
 * @psalm-import-type FrozenContext from Types
 */
final class ContextFreezer
{
    /**
     * Freeze a context for the given operation, or into a placeholder when the
     * context data cannot be serialized.
     *
     * @param 'open'|'event'|'close' $operation
     *
     * @return FrozenContext
     */
    public function freeze(AbstractContext $context, string $operation): array
    {
        $type = $this->constantToString($context::TYPE);
        $schemaUrl = $this->constantToString($context::SCHEMA_URL);

        try {
            return [
                'type' => $type,
                'schemaUrl' => $schemaUrl,
                'context' => $this->freezeData($context),
                'diagnostic' => null,
            ];
        } catch (Throwable $throwable) {
            $diagnostic = [
                'kind' => 'context_serialization_failed',
                'message' => 'Context serialization failed.',
                'exceptionClass' => $throwable::class,
            ];

            return [
                'type' => CoreSchema::INVALID_CONTEXT_TYPE,
                'schemaUrl' => CoreSchema::INVALID_CONTEXT_URL,
                'context' => $this->placeholderContext($operation, $diagnostic, $type, $schemaUrl),
                'diagnostic' => $diagnostic,
            ];
        }
    }

    /**
     * Freeze user values as an inert JSON tree so later snapshots and flushes
     * never invoke nested JsonSerializable objects a second time.
     *
     * @return ContextData
     */
    public function freezeData(AbstractContext $context): array
    {
        if ($context instanceof JsonSerializable) {
            /** @var mixed $serialized */
            $serialized = $context->jsonSerialize();
            if (is_array($serialized) && $this->hasOnlyStringKeys($serialized)) {
                return $this->freezeArray($serialized);
            }

            // Non-empty list results are not valid object contexts. Preserve
            // the legacy public-property fallback instead of storing the list.
        }

        /** @var ContextData $mixedArray */
        $mixedArray = (array) $context;

        return $this->freezeArray($mixedArray);
    }

    /**
     * @param array<mixed> $context
     *
     * @return ContextData
     */
    private function freezeArray(array $context): array
    {
        $json = json_encode($context, JSON_THROW_ON_ERROR);

        return $this->contextFromDecoded(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /** @return ContextData */
    private function contextFromDecoded(mixed $frozen): array
    {
        if (is_array($frozen)) {
            // Only an empty PHP context reaches this JSON-list branch.
            return [];
        }

        if (! $frozen instanceof stdClass) {
            return [];
        }

        $properties = get_object_vars($frozen);
        $keys = array_map(
            static fn (int|string $key): string => strval($key),
            array_keys($properties),
        );

        return array_combine($keys, array_values($properties));
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
     * @param DiagnosticData $diagnostic
     *
     * @return ContextData
     */
    private function placeholderContext(string $operation, array $diagnostic, string $type, string $schemaUrl): array
    {
        return [
            'operation' => $operation,
            'errors' => [
                [
                    'kind' => $diagnostic['kind'],
                    'message' => $diagnostic['message'],
                ],
            ],
            'originalType' => $type,
            'originalSchemaUrl' => $schemaUrl,
        ];
    }

    /** Read a class constant that should be a string, degrading to its debug type. */
    private function constantToString(mixed $value): string
    {
        return is_string($value) ? $value : get_debug_type($value);
    }
}
