<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

final class ModeInvalidMetadataContext extends AbstractContext
{
    public const TYPE = 'Invalid-Type';
    public const SCHEMA_URL = 'invalid-schema';

    public string $message = 'discarded';
}
