<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree\Fake;

use Koriym\SemanticLogger\AbstractContext;

final class FakeNestedContext extends AbstractContext
{
    public const TYPE = 'request';
    public const SCHEMA_URL = 'https://example.com/schemas/request.json';

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly array $headers,
    ) {
    }
}
