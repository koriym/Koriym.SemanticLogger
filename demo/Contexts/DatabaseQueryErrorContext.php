<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Database Query Error Context for failed database query
 */
final class DatabaseQueryErrorContext extends AbstractContext
{
    public const TYPE = 'database_query_error';
    public const SCHEMA_URL = './schemas/database_query_error.json';

    public function __construct(public readonly array $errorData)
    {
    }
}
