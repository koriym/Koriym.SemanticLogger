<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

final class PrivateOnlyContext extends AbstractContext
{
    public const TYPE = 'private_only';
    public const SCHEMA_URL = 'https://example.com/schemas/private-only.json';

    public function __construct(
        private readonly string $secret = 'hidden',
    ) {
    }

    public function secret(): string
    {
        return $this->secret;
    }
}
