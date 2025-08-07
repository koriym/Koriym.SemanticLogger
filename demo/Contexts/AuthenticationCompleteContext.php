<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Authentication Complete Context for successful authentication completion
 */
final class AuthenticationCompleteContext extends AbstractContext
{
    public const TYPE = 'authentication_response';
    public const SCHEMA_URL = './schemas/authentication_response.json';

    public function __construct(public readonly array $authResult)
    {
    }
}
