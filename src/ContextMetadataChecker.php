<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use function get_debug_type;
use function is_string;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_starts_with;

use const PHP_URL_SCHEME;

/**
 * Static vocabulary checks the runtime deliberately does not enforce.
 *
 * The logger records whatever it is given — the log is data. This checker is
 * where malformed vocabulary becomes visible: context types must be lowercase
 * snake_case outside the core-owned semantic_logger_* namespace, and schema
 * URLs must be absolute URIs or ./schemas/<name>.json relative paths.
 */
final class ContextMetadataChecker
{
    /** Context types must be lowercase snake_case outside the core-owned namespace. */
    private const TYPE_PATTERN = '/^[a-z_]+$/D';
    private const RESERVED_TYPE_PREFIX = 'semantic_logger_';
    private const RELATIVE_SCHEMA_PATTERN = '/^\.\/schemas\/[a-zA-Z0-9_-]+\.json$/D';
    private const URI_SCHEME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9+.-]*$/D';

    /** @return list<string> Violations found; empty when the metadata is valid. */
    public function check(object $contextData, string $path): array
    {
        $violations = [];

        /** @var mixed $type */
        $type = $contextData->type ?? null;
        $typeValid = is_string($type) && $this->isValidType($type);
        if (! $typeValid) {
            $violations[] = sprintf(
                '[%s] Invalid context type: %s (must match ^[a-z_]+$ and must not use the %s* namespace)',
                $path,
                is_string($type) ? $type : get_debug_type($type),
                self::RESERVED_TYPE_PREFIX,
            );
        }

        /** @var mixed $schemaUrl */
        $schemaUrl = $contextData->schemaUrl ?? null;
        $schemaUrlValid = is_string($schemaUrl) && $this->isValidSchemaUrl($schemaUrl);
        if (! $schemaUrlValid) {
            $violations[] = sprintf(
                '[%s] Invalid context schemaUrl: %s (must be an absolute URI or ./schemas/<name>.json)',
                $path,
                is_string($schemaUrl) ? $schemaUrl : get_debug_type($schemaUrl),
            );
        }

        return $violations;
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
}
