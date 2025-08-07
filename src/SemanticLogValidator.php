<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSchema\Validator;
use function file_exists;
use function file_get_contents;
use function json_decode;
use function realpath;
use function sprintf;

final class SemanticLogValidator implements SemanticLogValidatorInterface
{
    public function validate(string $file, string $schemaDir): void
    {
        if (!file_exists($file)) {
            throw new \InvalidArgumentException("Log file not found: {$file}");
        }

        if (!file_exists($schemaDir)) {
            throw new \InvalidArgumentException("Schema directory not found: {$schemaDir}");
        }

        $logData = json_decode(file_get_contents($file), true);
        if ($logData === null) {
            throw new \InvalidArgumentException("Invalid JSON in log file: {$file}");
        }

        $violations = [];
        
        // Validate all contexts recursively
        $this->validateContexts($logData, $schemaDir, $violations);
        
        if (!empty($violations)) {
            $this->reportViolations($violations);
            throw new \RuntimeException(sprintf('Validation failed with %d violations', count($violations)));
        }
        
        echo "✅ All contexts validate successfully!\n";
    }

    /**
     * Extract and validate all contexts from log data
     * 
     * @param array<string, mixed> $data
     * @param string $schemaDir
     * @param array<string> $violations
     */
    private function validateContexts(array $data, string $schemaDir, array &$violations): void
    {
        // Validate open contexts (recursive)
        if (isset($data['open'])) {
            $this->validateContext($data['open'], $schemaDir, 'open', $violations);
        }

        // Validate close contexts (recursive)
        if (isset($data['close'])) {
            $this->validateContext($data['close'], $schemaDir, 'close', $violations);
        }

        // Validate event contexts
        if (isset($data['events']) && is_array($data['events'])) {
            foreach ($data['events'] as $index => $event) {
                $this->validateContext($event, $schemaDir, "events[{$index}]", $violations);
            }
        }
    }

    /**
     * Validate a single context against its schema
     * 
     * @param array<string, mixed> $contextData
     * @param string $schemaDir
     * @param string $path
     * @param array<string> $violations
     */
    private function validateContext(array $contextData, string $schemaDir, string $path, array &$violations): void
    {
        // Check if context has schemaUrl (non-standard but our approach)
        if (isset($contextData['schemaUrl'], $contextData['context'], $contextData['type'])) {
            $schemaUrl = $contextData['schemaUrl'];
            $type = $contextData['type'];
            $context = $contextData['context'];

            // Resolve schema file path
            $schemaFile = $this->resolveSchemaPath($schemaUrl, $schemaDir);
            
            if ($schemaFile === null) {
                $violations[] = "[{$path}] Schema file not found: {$schemaUrl}";
                return;
            }

            // Load and validate schema
            $schema = json_decode(file_get_contents($schemaFile));
            if ($schema === null) {
                $violations[] = "[{$path}] Invalid schema JSON: {$schemaFile}";
                return;
            }

            // Validate context against schema
            $validator = new Validator();
            $contextObj = json_decode(json_encode($context));
            $validator->validate($contextObj, $schema);

            if (!$validator->isValid()) {
                foreach ($validator->getErrors() as $error) {
                    $property = $error['property'] ?? '';
                    $message = $error['message'] ?? 'Validation failed';
                    $violations[] = "[{$path}.context ({$type})] {$message} at '{$property}'";
                }
            } else {
                echo "✅ {$path} ({$type}) validates against {$schemaUrl}\n";
            }
        }

        // Recursively validate nested open/close
        if (isset($contextData['open'])) {
            $this->validateContext($contextData['open'], $schemaDir, "{$path}.open", $violations);
        }
        
        if (isset($contextData['close'])) {
            $this->validateContext($contextData['close'], $schemaDir, "{$path}.close", $violations);
        }
    }

    /**
     * Resolve schema URL to local file path
     */
    private function resolveSchemaPath(string $schemaUrl, string $schemaDir): ?string
    {
        // Handle relative paths like "./schemas/http_request.json"
        if (str_starts_with($schemaUrl, './schemas/')) {
            $filename = basename($schemaUrl);
            $schemaFile = realpath($schemaDir . '/' . $filename);
            return $schemaFile !== false ? $schemaFile : null;
        }

        // Handle direct filenames
        if (!str_contains($schemaUrl, '/')) {
            $schemaFile = realpath($schemaDir . '/' . $schemaUrl);
            return $schemaFile !== false ? $schemaFile : null;
        }

        // For absolute URLs, try to extract filename and map to local files
        $filename = basename($schemaUrl);
        
        // Special case: complex-query.json from external URL
        if ($filename === 'complex-query.json') {
            $localFile = realpath($schemaDir . '/complex_query.json');
            if ($localFile !== false) {
                return $localFile;
            }
        }
        
        // General case: try exact filename match
        $schemaFile = realpath($schemaDir . '/' . $filename);
        return $schemaFile !== false ? $schemaFile : null;
    }


    /**
     * Report validation violations
     * 
     * @param array<string> $violations
     */
    private function reportViolations(array $violations): void
    {
        echo "❌ Validation failed with the following violations:\n";
        foreach ($violations as $violation) {
            echo "  {$violation}\n";
        }
        echo "\n📖 For error format details, see: https://json-schema.org/understanding-json-schema/reference/generic.html#validation-keywords\n";
    }
}