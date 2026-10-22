<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

final class MixedVisibilityContext extends AbstractContext
{
    public const TYPE = 'mixed_visibility_context';
    public const SCHEMA_URL = 'https://example.com/schemas/mixed-visibility.json';

    public function __construct(
        public readonly string $name,
        protected readonly string $token,
        private readonly string $secret,
    ) {
    }
}
