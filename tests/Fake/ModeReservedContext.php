<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

final class ModeReservedContext extends AbstractContext
{
    public const TYPE = 'semantic_logger_consumer';
    public const SCHEMA_URL = './schemas/mode-context.json';
}
