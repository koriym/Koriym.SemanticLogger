<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;

final class ModeThrowingContext extends AbstractContext implements JsonSerializable
{
    public const TYPE = 'mode_context';
    public const SCHEMA_URL = './schemas/mode-context.json';

    #[Override]
    public function jsonSerialize(): mixed
    {
        throw new ModeTestSerializationException('Cannot serialize test context.');
    }
}
