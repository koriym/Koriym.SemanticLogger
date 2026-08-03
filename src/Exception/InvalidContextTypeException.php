<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Exception;

final class InvalidContextTypeException extends InvalidContextMetadataException
{
    public function __construct()
    {
        parent::__construct('Invalid semantic context TYPE: expected a non-empty lowercase type matching ^[a-z_]+$ outside the reserved semantic_logger_* namespace.');
    }
}
