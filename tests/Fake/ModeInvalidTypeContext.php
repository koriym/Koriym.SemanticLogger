<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

final class ModeInvalidTypeContext extends AbstractContext
{
    public const TYPE = 'Invalid-Type';
    public const SCHEMA_URL = './schemas/mode-context.json';
}
