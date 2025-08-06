<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * HTTP Request Context for e-commerce API requests
 */
final class HttpRequestContext extends AbstractContext
{
    public const TYPE = 'http_request';
    public const SCHEMA_URL = '../schemas/http_request.json';

    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $headers,
        public readonly ?array $body = null,
        public readonly string $userAgent = '',
        public readonly string $clientIp = '',
    ) {
    }
}