<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use function array_keys;
use function array_map;
use function array_values;
use function basename;
use function dirname;
use function glob;
use function is_file;
use function str_replace;

/**
 * @psalm-import-type JsonMap from Types
 * @psalm-import-type SchemaTypeMap from Types
 */
final class DynamicSchemaGenerator
{
    public function __construct(
        private readonly string $schemasDirectory,
        private readonly string $baseSchemaPath = '',
    ) {
    }

    /**
     * Generate log-oriented public schema with dynamic type-based context validation.
     *
     * @return JsonMap
     */
    public function generateCombinedSchema(): array
    {
        $contextTypes = $this->discoverContextTypes();

        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            '$id' => 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log-generated.json',
            'title' => 'Dynamic Semantic Logger Schema',
            'description' => 'Auto-generated log-oriented semantic log schema with type-based context validation',
            'type' => 'object',
            'required' => ['$schema', 'log'],
            'properties' => [
                '$schema' => ['$ref' => '#/definitions/schemaUrl'],
                'log' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/definitions/rootRecord'],
                ],
                'types' => [
                    'type' => 'object',
                    'additionalProperties' => ['$ref' => '#/definitions/schemaUrl'],
                ],
                'links' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'rel' => ['type' => 'string'],
                            'href' => ['type' => 'string', 'format' => 'uri'],
                            'title' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                        ],
                        'required' => ['rel', 'href'],
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'additionalProperties' => false,
            'definitions' => [
                'schemaUrl' => $this->generateSchemaUrlDefinition(),
                'operationId' => [
                    'type' => 'string',
                    'pattern' => '^[a-z_]+_[0-9]+$',
                    'description' => 'Unique operation identifier',
                ],
                'openIdReference' => [
                    'type' => 'string',
                    'pattern' => '^[a-z_]+_[0-9]+$',
                    'description' => 'The id of the open an orphan close attempted to close',
                ],
                'contextType' => [
                    'type' => 'string',
                    'pattern' => '^[a-z_]+$',
                    'description' => 'Context type identifier; semantic_logger_* is reserved for core entries',
                ],
                'rootRecord' => [
                    'oneOf' => [
                        ['$ref' => '#/definitions/record'],
                        ['$ref' => '#/definitions/orphanClose'],
                    ],
                ],
                'record' => $this->generateRecordSchema($contextTypes),
                'close' => $this->generateCloseSchema($contextTypes),
                'orphanClose' => $this->generateOrphanCloseSchema($contextTypes),
            ],
        ];
    }

    /**
     * Discover all context types from schema files
     *
     * @return SchemaTypeMap Map of type => schema file path
     */
    private function discoverContextTypes(): array
    {
        $schemaFiles = glob($this->schemasDirectory . '/*.json');
        if ($schemaFiles === false) {
            return [];
        }

        $contextTypes = [];

        foreach ($schemaFiles as $file) {
            if (! is_file($file)) {
                continue;
            }

            $filename = basename($file, '.json');
            $type = str_replace(['-', '_'], '_', $filename);

            $contextTypes[$type] = $this->getRelativePath($file);
        }

        return $contextTypes;
    }

    /**
     * Generate record schema (interval/point, non-root) with dynamic type validation.
     *
     * @param SchemaTypeMap $contextTypes
     *
     * @return JsonMap
     */
    private function generateRecordSchema(array $contextTypes): array
    {
        $schema = [
            'type' => 'object',
            'required' => ['type', 'context'],
            'properties' => [
                'id' => ['$ref' => '#/definitions/operationId'],
                'type' => ['$ref' => '#/definitions/contextType'],
                'schemaUrl' => ['$ref' => '#/definitions/schemaUrl'],
                'context' => ['type' => 'object', 'additionalProperties' => true],
                'log' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/definitions/record'],
                ],
                'close' => ['$ref' => '#/definitions/close'],
                'profile' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'additionalProperties' => false,
        ];

        if (! empty($contextTypes)) {
            $schema['allOf'] = $this->generateTypeConditions($contextTypes);
        }

        return $schema;
    }

    /**
     * Generate close schema (nested on its matching record) with dynamic type validation.
     *
     * @param SchemaTypeMap $contextTypes
     *
     * @return JsonMap
     */
    private function generateCloseSchema(array $contextTypes): array
    {
        $schema = [
            'type' => 'object',
            'required' => ['type', 'context'],
            'properties' => [
                'id' => ['$ref' => '#/definitions/operationId'],
                'type' => ['$ref' => '#/definitions/contextType'],
                'schemaUrl' => ['$ref' => '#/definitions/schemaUrl'],
                'context' => ['type' => 'object', 'additionalProperties' => true],
                'profile' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'additionalProperties' => false,
        ];

        if (! empty($contextTypes)) {
            $schema['allOf'] = $this->generateTypeConditions($contextTypes);
        }

        return $schema;
    }

    /**
     * Generate orphan close schema (root-only, carries openId) with dynamic type validation.
     *
     * @param SchemaTypeMap $contextTypes
     *
     * @return JsonMap
     */
    private function generateOrphanCloseSchema(array $contextTypes): array
    {
        $schema = [
            'type' => 'object',
            'required' => ['type', 'context', 'openId'],
            'properties' => [
                'id' => ['$ref' => '#/definitions/operationId'],
                'type' => ['$ref' => '#/definitions/contextType'],
                'schemaUrl' => ['$ref' => '#/definitions/schemaUrl'],
                'context' => ['type' => 'object', 'additionalProperties' => true],
                'openId' => ['$ref' => '#/definitions/openIdReference'],
                'profile' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'additionalProperties' => false,
        ];

        if (! empty($contextTypes)) {
            $schema['allOf'] = $this->generateTypeConditions($contextTypes);
        }

        return $schema;
    }

    /**
     * Generate if/then/else conditions for each context type
     *
     * @param SchemaTypeMap $contextTypes
     *
     * @return list<JsonMap>
     */
    private function generateTypeConditions(array $contextTypes): array
    {
        return array_map(
            static fn (string $type, string $schemaPath): array => [
                'if' => [
                    'properties' => [
                        'type' => ['const' => $type],
                    ],
                ],
                'then' => [
                    'properties' => [
                        'context' => ['$ref' => $schemaPath],
                    ],
                ],
            ],
            array_keys($contextTypes),
            array_values($contextTypes),
        );
    }

    /**
     * Convert absolute path to relative path for schema references
     */
    private function getRelativePath(string $filePath): string
    {
        if ($this->baseSchemaPath !== '') {
            return str_replace($this->baseSchemaPath, '.', dirname($filePath)) . '/' . basename($filePath);
        }

        return './schemas/' . basename($filePath);
    }

    /** @return JsonMap */
    private function generateSchemaUrlDefinition(): array
    {
        return [
            'oneOf' => [
                [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'Absolute URI to context schema',
                ],
                [
                    'type' => 'string',
                    'pattern' => '^\\./schemas/[a-zA-Z0-9_-]+\\.json$',
                    'description' => 'Relative path to local schema file',
                ],
            ],
            'description' => 'Context schema reference for AI-human understanding alignment. While humans naturally understand what this context represents, AI needs explicit schema guidance to achieve the same level of comprehension about data structure, constraints, and business meaning. Supports both absolute URLs and relative file paths.',
            '$comment' => 'Bridge: Humans intuitively understand context meaning; AI uses this schema reference to gain equivalent understanding.',
        ];
    }
}
