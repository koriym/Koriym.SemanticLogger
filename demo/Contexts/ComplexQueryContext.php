<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Koriym\SemanticLogger\AbstractContext;

final class ComplexQueryContext extends AbstractContext
{
    public const TYPE = 'complex_query';
    public const SCHEMA_URL = 'https://example.com/schema/complex-query.json';

    public function __construct(
        public readonly string $queryType,
        public readonly string $table,
        public readonly array $parameters,
        public readonly int $fieldCount,
        public readonly float $executionTime,
        public readonly int $affectedRows,
        public readonly bool $hasError,
        public readonly ?string $customerId = null,
    ) {
    }
}