<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Database Connection Context for database operations
 */
final class DatabaseConnectionContext extends AbstractContext
{
    public const TYPE = 'database_connection';
    public const SCHEMA_URL = '../schemas/database_connection.json';

    public function __construct(
        public readonly string $driver,
        public readonly string $host,
        public readonly string $database,
        public readonly float $connectionTime,
        public readonly bool $pooled = false,
    ) {
    }
}
