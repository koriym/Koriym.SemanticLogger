<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use function basename;
use function file_get_contents;
use function get_object_vars;
use function is_object;
use function is_string;
use function json_decode;
use function property_exists;
use function realpath;
use function str_contains;
use function str_starts_with;

/**
 * Resolves a record's effective JSON schema (own `schemaUrl` vs document-level
 * `types[type]`), locates schema files on disk, and loads them.
 *
 * Owns the per-document type->schemaUrl 1:1 map built up while one log is
 * validated: a fresh instance is created per `validate()` call so the map
 * never leaks between documents.
 */
final class SchemaResolver
{
    /** @var array<string, string> */
    private array $typeUrlMap = [];

    /** @return array<string, string> */
    public function extractTypesMap(object $data): array
    {
        if (! isset($data->types) || ! is_object($data->types)) {
            return [];
        }

        $types = [];
        /** @var array<string, mixed> $typeEntries */
        $typeEntries = get_object_vars($data->types);
        /** @psalm-suppress MixedAssignment $schemaUrl is dynamic JSON-decoded data; narrowed by is_string() below. */
        foreach ($typeEntries as $type => $schemaUrl) {
            if (is_string($schemaUrl)) {
                $types[$type] = $schemaUrl;
            }
        }

        return $types;
    }

    /**
     * Resolve a record's effective schema: its own `schemaUrl` if present,
     * otherwise the document-level `types[type]`. Both present and disagreeing,
     * or neither present, is an error; across the document one `type` must
     * resolve to exactly one URL.
     *
     * @param array<string, string> $types
     * @param list<string>          $violations
     *
     * @param-out list<string>          $violations
     */
    public function resolveSchemaUrl(object $contextData, string $type, array $types, string $kind, string $path, array &$violations): string|null
    {
        $ownUrl = $this->extractSchemaUrl($contextData);
        $typesUrl = $types[$type] ?? null;

        if ($ownUrl !== null && $typesUrl !== null && $ownUrl !== $typesUrl) {
            $violations[] = "{$kind} {$path}: schemaUrl '{$ownUrl}' disagrees with types['{$type}'] = '{$typesUrl}'";

            return null;
        }

        $resolved = $ownUrl ?? $typesUrl;
        if ($resolved === null) {
            $violations[] = "{$kind} {$path}: no schema for type {$type}";

            return null;
        }

        if (isset($this->typeUrlMap[$type]) && $this->typeUrlMap[$type] !== $resolved) {
            $violations[] = "{$kind} {$path}: type '{$type}' already resolves to '{$this->typeUrlMap[$type]}', cannot also resolve to '{$resolved}'";

            return null;
        }

        $this->typeUrlMap[$type] = $resolved;

        return $resolved;
    }

    /** A copy of the record with `schemaUrl` set to the resolved value, for metadata checks that read it directly off the object. */
    public function withResolvedSchemaUrl(object $contextData, string $schemaUrl): object
    {
        $properties = get_object_vars($contextData);
        $properties['schemaUrl'] = $schemaUrl;

        return (object) $properties;
    }

    public function extractSchemaUrl(object $contextData): string|null
    {
        if (property_exists($contextData, '$schema') && is_string($contextData->{'$schema'})) {
            return $contextData->{'$schema'};
        }

        if (isset($contextData->schemaUrl) && is_string($contextData->schemaUrl)) {
            return $contextData->schemaUrl;
        }

        return null;
    }

    /**
     * Resolve schema URL to local file path
     */
    public function resolveSchemaPath(string $schemaUrl, string $schemaDir): string|null
    {
        // Handle relative paths like "./schemas/http_request.json"
        if (str_starts_with($schemaUrl, './schemas/')) {
            $filename = basename($schemaUrl);
            $schemaFile = realpath($schemaDir . '/' . $filename);

            return $schemaFile !== false ? $schemaFile : null;
        }

        // Handle direct filenames
        if (! str_contains($schemaUrl, '/')) {
            $schemaFile = realpath($schemaDir . '/' . $schemaUrl);

            return $schemaFile !== false ? $schemaFile : null;
        }

        // For absolute URLs, try to extract filename and map to local files
        $filename = basename($schemaUrl);

        // Special case: complex-query.json from external URL
        if ($filename === 'complex-query.json') {
            $localFile = realpath($schemaDir . '/complex_query.json');
            if ($localFile !== false) {
                return $localFile;
            }
        }

        // General case: try exact filename match
        $schemaFile = realpath($schemaDir . '/' . $filename);

        return $schemaFile !== false ? $schemaFile : null;
    }

    /**
     * @param list<string> $violations
     *
     * @param-out list<string> $violations
     */
    public function loadSchema(string $schemaFile, string $kind, string $path, array &$violations): object|null
    {
        $schemaContents = file_get_contents($schemaFile);
        if ($schemaContents === false) {
            $violations[] = "{$kind} {$path}: cannot read schema file: {$schemaFile}";

            return null;
        }

        /** @var object|null $schema */
        $schema = json_decode($schemaContents);
        if ($schema === null) {
            $violations[] = "{$kind} {$path}: invalid schema JSON: {$schemaFile}";

            return null;
        }

        return $schema;
    }
}
