<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree\Exception;

/**
 * A schema directory was given without snapshot output.
 *
 * Schemas are read only to mask `"x-volatile": true` values, which happens in snapshot output alone.
 *
 * Message format: the schema directory
 */
final class SchemaDirWithoutSnapshot extends RuntimeException
{
    public function __construct(public readonly string $schemaDir)
    {
        parent::__construct($schemaDir);
    }
}
