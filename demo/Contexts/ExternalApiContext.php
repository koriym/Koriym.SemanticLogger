<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * External API Context for third-party service call initiation
 */
final class ExternalApiContext extends AbstractContext
{
    public const TYPE = 'external_api_request';
    public const SCHEMA_URL = './schemas/external_api_request.json';

    public function __construct(
        public readonly string $service,
        public readonly string $endpoint,
        public readonly string $method,
        public readonly int $statusCode = 0,
        public readonly float $responseTime = 0.0,
        public readonly int $requestSize = 0,
        public readonly int $responseSize = 0,
        public readonly int $errorCode = 0,
    ) {
    }
}
