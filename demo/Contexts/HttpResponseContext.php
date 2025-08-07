<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * HTTP Response Context for HTTP response operations
 */
final class HttpResponseContext extends AbstractContext
{
    public const TYPE = 'http_response';
    public const SCHEMA_URL = './schemas/http_response.json';

    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly int $contentLength,
        public readonly string $contentType,
        public readonly float $responseTime,
        public readonly bool $cached = false,
    ) {
    }
}
