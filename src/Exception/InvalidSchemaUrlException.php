<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Exception;

final class InvalidSchemaUrlException extends InvalidContextMetadataException
{
    public function __construct()
    {
        parent::__construct('Invalid semantic context SCHEMA_URL: expected an absolute URI or ./schemas/<name>.json.');
    }
}
