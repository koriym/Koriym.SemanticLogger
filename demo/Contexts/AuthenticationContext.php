<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Authentication Context for authentication attempt initiation
 */
final class AuthenticationContext extends AbstractContext
{
    public const TYPE = 'authentication_request';
    public const SCHEMA_URL = '../schemas/authentication.json';

    public function __construct(
        public readonly string $method,
        public readonly string|null $token = null,
        public readonly array $requestMetadata = [],
    ) {
    }
}
