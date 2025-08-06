<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Business Logic Context for core business operations
 */
final class BusinessLogicContext extends AbstractContext
{
    public const TYPE = 'business_logic';
    public const SCHEMA_URL = '../schemas/business_logic.json';

    public function __construct(
        public readonly string $operation,
        public readonly array $inputData,
        public readonly array $outputData,
        public readonly array $validationRules,
        public readonly bool $success,
    ) {
    }
}