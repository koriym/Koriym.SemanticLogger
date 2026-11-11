<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree\Exception;

use RuntimeException;

/**
 * Message format: the schema directory
 */
final class SchemaDirNotFound extends RuntimeException
{
    public function __construct(public readonly string $schemaDir)
    {
        parent::__construct($schemaDir);
    }
}
