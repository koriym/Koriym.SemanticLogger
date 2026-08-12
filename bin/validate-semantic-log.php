#!/usr/bin/env php
<?php

declare(strict_types=1);

/** @psalm-suppress UnresolvableInclude */
require_once $GLOBALS['_composer_autoload_path'] ?? dirname(__DIR__) . '/vendor/autoload.php';

use Koriym\SemanticLogger\SemanticLogValidator;

/** @var list<string> $args */
$args = $argv ?? [];
$failOnDiagnostics = false;
$flagIndex = array_search('--fail-on-diagnostics', $args, true);
if ($flagIndex !== false) {
    $failOnDiagnostics = true;
    unset($args[$flagIndex]);
    $args = array_values($args);
}

if (count($args) < 2) {
    echo "Usage: {$argv[0]} [--fail-on-diagnostics] <semantic-log.json> [schema-directory]\n";
    echo "\n";
    echo "Validates semantic log file against individual context schemas\n";
    echo "Uses schemaUrl properties to find and validate each context\n";
    echo "\n";
    echo "Options:\n";
    echo "  --fail-on-diagnostics  Exit non-zero when the log contains diagnostic\n";
    echo "                         entries recorded by the logger (semantic_logger_error /\n";
    echo "                         semantic_logger_invalid_context)\n";
    echo "\n";
    echo "Arguments:\n";
    echo "  semantic-log.json   Path to semantic log file\n";
    echo "  schema-directory    Directory containing schema files (default: ./schemas/)\n";
    echo "\n";
    echo "Example:\n";
    echo "  {$argv[0]} demo/semantic-log.json demo/schemas/\n";
    exit(1);
}

$logFile = $args[1];
$schemaDir = $args[2] ?? './schemas/';

try {
    $validator = new SemanticLogValidator();
    $validator->validate($logFile, $schemaDir, $failOnDiagnostics);
    echo "\n🎉 Semantic log validation passed!\n";
} catch (Exception $e) {
    echo "\n💥 Validation failed: {$e->getMessage()}\n";
    exit(1);
}
