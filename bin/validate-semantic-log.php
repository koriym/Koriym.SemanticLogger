#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Koriym\SemanticLogger\SemanticLogValidator;

if ($argc < 2) {
    echo "Usage: {$argv[0]} <semantic-log.json> [schema-directory]\n";
    echo "\n";
    echo "Validates semantic log file against individual context schemas\n";
    echo "Uses schemaUrl properties to find and validate each context\n";
    echo "\n";
    echo "Arguments:\n";
    echo "  semantic-log.json   Path to semantic log file\n";
    echo "  schema-directory    Directory containing schema files (default: ./schemas/)\n";
    echo "\n";
    echo "Example:\n";
    echo "  {$argv[0]} demo/semantic-log.json demo/schemas/\n";
    exit(1);
}

$logFile = $argv[1];
$schemaDir = $argv[2] ?? './schemas/';

try {
    $validator = new SemanticLogValidator();
    $validator->validate($logFile, $schemaDir);
    echo "\n🎉 Semantic log validation passed!\n";
} catch (Exception $e) {
    echo "\n💥 Validation failed: {$e->getMessage()}\n";
    exit(1);
}