<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;

final class CountingContext extends AbstractContext implements JsonSerializable
{
    public const TYPE = 'counting';
    public const SCHEMA_URL = 'https://example.com/schemas/counting.json';

    public static int $serialized = 0;

    /** @return array<string, int> */
    #[Override]
    public function jsonSerialize(): array
    {
        self::$serialized++;

        return ['n' => self::$serialized];
    }
}
