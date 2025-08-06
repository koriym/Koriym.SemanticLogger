<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Database Query Context for database query initiation
 */
final class DatabaseQueryContext extends AbstractContext
{
    public const TYPE = 'database_query_request';
    public const SCHEMA_URL = '../schemas/database_query_request.json';

    public function __construct(
        public readonly string $operation,
        public readonly string $table,
        public readonly array $conditions,
        public readonly ?string $sql = null,
    ) {
    }
}