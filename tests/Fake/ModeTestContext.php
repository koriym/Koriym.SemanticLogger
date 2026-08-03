<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

final class ModeTestContext extends AbstractContext
{
    public const TYPE = 'mode_context';
    public const SCHEMA_URL = './schemas/mode-context.json';
    public const ROOT_SCHEMA = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';

    public function __construct(public readonly string $message)
    {
    }
}
