<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Koriym\SemanticLogger\Exception\InvalidContextTypeException;
use Koriym\SemanticLogger\Exception\InvalidSchemaUrlException;
use stdClass;
use Throwable;

use function array_combine;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function get_debug_type;
use function get_object_vars;
use function is_array;
use function is_string;
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
 * @psalm-import-type PreparedContext from Types
 * @psalm-import-type PreparedMetadata from Types
 * @psalm-import-type PreparedSerialization from Types
 */
final class ContextPreparer
{
    private const RESERVED_TYPE_PREFIX = 'semantic_logger_';
    private const TYPE_PATTERN = '/^[a-z_]+$/D';
    private const RELATIVE_SCHEMA_PATTERN = '/^\.\/schemas\/[a-zA-Z0-9_-]+\.json$/D';
    private const URI_SCHEME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9+.-]*$/D';

    public function __construct(
        private readonly SemanticLoggerMode $mode,
    ) {
    }

    /** @return PreparedContext */
    public function prepare(AbstractContext $context, string $operation): array
    {
        $metadata = $this->prepareMetadata($context::TYPE, $context::SCHEMA_URL);
        $serialization = $this->prepareSerialization($context);
        $diagnostics = array_merge($metadata['diagnostics'], $serialization['diagnostics']);

        if ($diagnostics === [] && $metadata['type'] !== null && $metadata['schemaUrl'] !== null && $serialization['context'] !== null) {
            return [
                'type' => $metadata['type'],
                'schemaUrl' => $metadata['schemaUrl'],
                'context' => $serialization['context'],
                'diagnostics' => [],
            ];
        }

        if ($serialization['context'] !== null) {
            $diagnostics = array_map(static function (array $diagnostic) use ($serialization): array {
                $diagnostic['discardedContext'] = $serialization['context'];

                return $diagnostic;
            }, $diagnostics);
        }

        return [
            'type' => CoreSchema::INVALID_CONTEXT_TYPE,
            'schemaUrl' => CoreSchema::INVALID_CONTEXT_URL,
            'context' => $this->placeholderContext($operation, $diagnostics),
            'diagnostics' => $diagnostics,
        ];
    }

    /** @return ContextData */
    public function freezeContext(AbstractContext $context): array
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

    /** @return PreparedMetadata */
    private function prepareMetadata(mixed $rawType, mixed $rawSchemaUrl): array
    {
        $typeResult = $this->prepareType($rawType);
        $schemaResult = $this->prepareSchemaUrl($rawSchemaUrl);
        $diagnostics = [];
        if ($typeResult['diagnostic'] !== null) {
            $diagnostics[] = $typeResult['diagnostic'];
        }

        if ($schemaResult['diagnostic'] !== null) {
            $diagnostics[] = $schemaResult['diagnostic'];
        }

        return [
            'type' => $typeResult['value'],
            'schemaUrl' => $schemaResult['value'],
            'diagnostics' => $diagnostics,
        ];
    }

    /** @return array{value: string|null, diagnostic: DiagnosticData|null} */
    private function prepareType(mixed $rawType): array
    {
        if (is_string($rawType) && $this->isValidType($rawType)) {
            return ['value' => $rawType, 'diagnostic' => null];
        }

        if ($this->mode === SemanticLoggerMode::Strict) {
            throw new InvalidContextTypeException();
        }

        return [
            'value' => null,
            'diagnostic' => [
                'kind' => 'invalid_type',
                'message' => 'Context TYPE must match ^[a-z_]+$ and must not use the semantic_logger_* namespace.',
                'originalType' => $this->originalValue($rawType),
            ],
        ];
    }

    /** @return array{value: string|null, diagnostic: DiagnosticData|null} */
    private function prepareSchemaUrl(mixed $rawSchemaUrl): array
    {
        if (is_string($rawSchemaUrl) && $this->isValidSchemaUrl($rawSchemaUrl)) {
            return ['value' => $rawSchemaUrl, 'diagnostic' => null];
        }

        if ($this->mode === SemanticLoggerMode::Strict) {
            throw new InvalidSchemaUrlException();
        }

        return [
            'value' => null,
            'diagnostic' => [
                'kind' => 'invalid_schema_url',
                'message' => 'Context SCHEMA_URL must be an absolute URI or ./schemas/<name>.json.',
                'originalSchemaUrl' => $this->originalValue($rawSchemaUrl),
            ],
        ];
    }

    /** @return PreparedSerialization */
    private function prepareSerialization(AbstractContext $context): array
    {
        try {
            return ['context' => $this->freezeContext($context), 'diagnostics' => []];
        } catch (Throwable $throwable) {
            if ($this->mode === SemanticLoggerMode::Strict) {
                throw $throwable;
            }

            return [
                'context' => null,
                'diagnostics' => [
                    [
                        'kind' => 'context_serialization_failed',
                        'message' => 'Context serialization failed.',
                        'exceptionClass' => $throwable::class,
                    ],
                ],
            ];
        }
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

    private function isValidType(string $type): bool
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            return false;
        }

        return ! str_starts_with($type, self::RESERVED_TYPE_PREFIX);
    }

    private function isValidSchemaUrl(string $schemaUrl): bool
    {
        if ($schemaUrl === '') {
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
}
