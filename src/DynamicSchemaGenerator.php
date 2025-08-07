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
 * Generates dynamic JSON Schema with if/then/else conditions based on context types
 */
final class DynamicSchemaGenerator
{
    public function __construct(
        private readonly string $schemasDirectory,
        private readonly string $baseSchemaPath = '',
    ) {
    }

    /**
     * Generate combined schema with dynamic type-based context validation
     */
    public function generateCombinedSchema(): array
    {
        $contextTypes = $this->discoverContextTypes();

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log-generated.json',
            'title' => 'Dynamic Semantic Logger Schema',
            'description' => 'Auto-generated schema with type-based context validation',
            'type' => 'object',
            'required' => ['schemaUrl', 'open', 'close'],
            'properties' => [
                'schemaUrl' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'URL to the semantic log schema',
                ],
                'open' => $this->generateOpenSchema($contextTypes),
                'close' => $this->generateCloseSchema($contextTypes),
                'events' => $this->generateEventsSchema($contextTypes),
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
            '$defs' => [
                'operationId' => [
                    'type' => 'string',
                    'pattern' => '^[a-z_]+_[0-9]+$',
                    'description' => 'Unique operation identifier',
                ],
                'openIdReference' => [
                    'type' => 'string',
                    'pattern' => '^[a-z_]+_[0-9]+$',
                    'description' => 'References a parent operation ID',
                ],
            ],
        ];
    }

    /**
     * Discover all context types from schema files
     *
     * @return array<string, string> Map of type => schema file path
     */
    private function discoverContextTypes(): array
    {
        $schemaFiles = glob($this->schemasDirectory . '/*.json');
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
     * Generate open schema with dynamic type validation
     */
    private function generateOpenSchema(array $contextTypes): array
    {
        $baseSchema = [
            'type' => 'object',
            'required' => ['id', 'type'],
            'properties' => [
                'id' => ['$ref' => '#/$defs/operationId'],
                'type' => ['type' => 'string'],
                'context' => ['type' => 'object', 'additionalProperties' => true],
                'open' => ['$ref' => '#/properties/open'],
            ],
            'additionalProperties' => true,
        ];

        if (! empty($contextTypes)) {
            $baseSchema['allOf'] = $this->generateTypeConditions($contextTypes);
        }

        return $baseSchema;
    }

    /**
     * Generate close schema with dynamic type validation
     */
    private function generateCloseSchema(array $contextTypes): array
    {
        $baseSchema = [
            'type' => 'object',
            'required' => ['id', 'type', 'openId'],
            'properties' => [
                'id' => ['$ref' => '#/$defs/operationId'],
                'type' => ['type' => 'string'],
                'openId' => ['$ref' => '#/$defs/openIdReference'],
                'context' => ['type' => 'object', 'additionalProperties' => true],
                'close' => ['$ref' => '#/properties/close'],
            ],
            'additionalProperties' => true,
        ];

        if (! empty($contextTypes)) {
            $baseSchema['allOf'] = $this->generateTypeConditions($contextTypes);
        }

        return $baseSchema;
    }

    /**
     * Generate events schema with dynamic type validation
     */
    private function generateEventsSchema(array $contextTypes): array
    {
        $eventItemSchema = [
            'type' => 'object',
            'required' => ['id', 'type', 'openId'],
            'properties' => [
                'id' => ['$ref' => '#/$defs/operationId'],
                'type' => ['type' => 'string'],
                'openId' => ['$ref' => '#/$defs/openIdReference'],
                'context' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'additionalProperties' => true,
        ];

        if (! empty($contextTypes)) {
            $eventItemSchema['allOf'] = $this->generateTypeConditions($contextTypes);
        }

        return [
            'type' => 'array',
            'items' => $eventItemSchema,
        ];
    }

    /**
     * Generate if/then/else conditions for each context type
     *
     * @param array<string, string> $contextTypes
     *
     * @return array<array>
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
}
