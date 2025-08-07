<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * External API Complete Context for successful third-party service call completion
 */
final class ExternalApiCompleteContext extends AbstractContext
{
    public const TYPE = 'external_api_response';
    public const SCHEMA_URL = './schemas/external_api.json';

    public function __construct(public readonly array $responseData)
    {
    }
}
