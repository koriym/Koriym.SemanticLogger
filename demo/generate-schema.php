<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Koriym\SemanticLogger\DynamicSchemaGenerator;

echo "=== Generating Dynamic Schema ===\n";

$generator = new DynamicSchemaGenerator(__DIR__ . '/schemas');
$combinedSchema = $generator->generateCombinedSchema();

$outputPath = __DIR__ . '/semantic-log-generated.json';
file_put_contents(
    $outputPath, 
    json_encode($combinedSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Generated dynamic schema: {$outputPath}\n";
echo "Discovered context types:\n";

// Show discovered types
$schemaFiles = glob(__DIR__ . '/schemas/*.json');
foreach ($schemaFiles as $file) {
    $filename = basename($file, '.json');
    $type = str_replace(['-', '_'], '_', $filename);
    echo "  - {$type} => {$file}\n";
}

echo "\nSchema generated successfully!\n";