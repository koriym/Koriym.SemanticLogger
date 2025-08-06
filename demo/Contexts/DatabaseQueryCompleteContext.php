<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Database Query Complete Context for successful database query completion
 */
final class DatabaseQueryCompleteContext extends AbstractContext
{
    public const TYPE = 'database_query_response';
    public const SCHEMA_URL = '../schemas/database_query_response.json';

    public function __construct(public readonly array $resultData) 
    {
    }
}