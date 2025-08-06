<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Authentication Error Context for failed authentication
 */
final class AuthenticationErrorContext extends AbstractContext
{
    public const TYPE = 'authentication_error';
    public const SCHEMA_URL = '../schemas/authentication_error.json';

    public function __construct(public readonly array $errorData) 
    {
    }
}