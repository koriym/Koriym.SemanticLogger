<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use InvalidArgumentException;
use RuntimeException;

interface SemanticLogValidatorInterface
{
    /**
     * Validate semantic log file against schema directory
     *
     * @param string $file      Path to semantic log JSON file
     * @param string $schemaDir Directory containing individual schema files
     *
     * @throws InvalidArgumentException When file or schema directory is not found.
     * @throws RuntimeException When validation fails.
     */
    public function validate(string $file, string $schemaDir): void;
}
