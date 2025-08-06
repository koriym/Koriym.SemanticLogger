<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * External API Error Context for failed third-party service call
 */
final class ExternalApiErrorContext extends AbstractContext
{
    public const TYPE = 'external_api_error';
    public const SCHEMA_URL = '../schemas/external_api_error.json';

    public function __construct(public readonly array $errorData)
    {
    }
}
