<?php

require_once 'vendor/autoload.php';

use JsonSchema\Validator;
use JsonSchema\Constraints\Constraint;

// Load the schema
$schema = json_decode(file_get_contents('docs/schemas/structured-log.json'));

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "❌ Schema JSON is invalid: " . json_last_error_msg() . "\n";
    exit(1);
}

// Test with example data from the schema
$examples = $schema->examples ?? [];

if (empty($examples)) {
    echo "❌ No examples found in schema\n";
    exit(1);
}

$validator = new Validator();
$allValid = true;

foreach ($examples as $index => $example) {
    echo "Validating example " . ($index + 1) . "...\n";
    
    $validator->validate($example, $schema, Constraint::CHECK_MODE_APPLY_DEFAULTS);
    
    if ($validator->isValid()) {
        echo "✅ Example " . ($index + 1) . " is valid\n";
    } else {
        echo "❌ Example " . ($index + 1) . " is invalid:\n";
        foreach ($validator->getErrors() as $error) {
            echo "  - [{$error['property']}] {$error['message']}\n";
        }
        $allValid = false;
    }
    
    // Reset validator for next example
    $validator->reset();
}

if ($allValid) {
    echo "\n🎉 All examples are valid!\n";
    exit(0);
} else {
    echo "\n❌ Some examples failed validation\n";
    exit(1);
}