<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Authentication Context for authentication attempt initiation
 */
final class AuthenticationContext extends AbstractContext
{
    public const TYPE = 'authentication_request';
    public const SCHEMA_URL = '../schemas/authentication_request.json';

    public function __construct(
        public readonly string $method,
        public readonly ?string $token = null,
        public readonly array $requestMetadata = [],
    ) {
    }
}