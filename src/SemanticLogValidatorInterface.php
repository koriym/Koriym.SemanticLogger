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
     * @param string $file              Path to semantic log JSON file
     * @param string $schemaDir         Directory containing individual schema files
     * @param bool   $failOnDiagnostics Treat diagnostic entries recorded by the
     *                                  logger (semantic_logger_error /
     *                                  semantic_logger_invalid_context) as failures
     *
     * @throws InvalidArgumentException When file or schema directory is not found.
     * @throws RuntimeException When validation fails.
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag") Deliberate CI switch
     * mirroring the --fail-on-diagnostics CLI flag; call sites should use
     * named arguments.
     */
    public function validate(string $file, string $schemaDir, bool $failOnDiagnostics = false): void;
}
