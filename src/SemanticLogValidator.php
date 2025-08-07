<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use InvalidArgumentException;
use JsonSchema\Validator;
use RuntimeException;

use function basename;
use function count;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function realpath;
use function sprintf;
use function str_contains;
use function str_starts_with;

final class SemanticLogValidator implements SemanticLogValidatorInterface
{
    public function validate(string $file, string $schemaDir): void
    {
        if (! file_exists($file)) {
            throw new InvalidArgumentException("Log file not found: {$file}");
        }

        if (! file_exists($schemaDir)) {
            throw new InvalidArgumentException("Schema directory not found: {$schemaDir}");
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new InvalidArgumentException("Cannot read log file: {$file}");
        }

        $logData = json_decode($contents, true);
        if ($logData === null) {
            throw new InvalidArgumentException("Invalid JSON in log file: {$file}");
        }

        $violations = [];

        // Validate all contexts recursively
        if (! is_array($logData)) {
            throw new InvalidArgumentException('Log data must be an array');
        }

        $this->validateContexts($logData, $schemaDir, $violations);

        if (! empty($violations)) {
            $this->reportViolations($violations);

            throw new RuntimeException(sprintf('Validation failed with %d violations', count($violations)));
        }

        echo "✅ All contexts validate successfully!\n";
    }

    /**
     * Extract and validate all contexts from log data
     *
     * @param array<string, mixed> $data
     * @param array<string>        $violations
     */
    private function validateContexts(array $data, string $schemaDir, array &$violations): void
    {
        // Validate open contexts (recursive)
        if (isset($data['open']) && is_array($data['open'])) {
            $this->validateContext($data['open'], $schemaDir, 'open', $violations);
        }

        // Validate close contexts (recursive)
        if (isset($data['close']) && is_array($data['close'])) {
            $this->validateContext($data['close'], $schemaDir, 'close', $violations);
        }

        // Validate event contexts
        if (isset($data['events']) && is_array($data['events'])) {
            foreach ($data['events'] as $index => $event) {
                if (is_array($event)) {
                    $this->validateContext($event, $schemaDir, "events[{$index}]", $violations);
                }
            }
        }
    }

    /**
     * Validate a single context against its schema
     *
     * @param array<string, mixed> $contextData
     * @param array<string>        $violations
     */
    private function validateContext(array $contextData, string $schemaDir, string $path, array &$violations): void
    {
        // Check if context has schemaUrl (non-standard but our approach)
        if (isset($contextData['schemaUrl'], $contextData['context'], $contextData['type'])) {
            $schemaUrl = $contextData['schemaUrl'];
            $type = $contextData['type'];
            $context = $contextData['context'];

            if (! is_string($schemaUrl) || ! is_string($type) || ! is_array($context)) {
                $violations[] = "[{$path}] Invalid context structure";

                return;
            }

            // Resolve schema file path
            $schemaFile = $this->resolveSchemaPath($schemaUrl, $schemaDir);

            if ($schemaFile === null) {
                $violations[] = "[{$path}] Schema file not found: {$schemaUrl}";

                return;
            }

            // Load and validate schema
            $schemaContents = file_get_contents($schemaFile);
            if ($schemaContents === false) {
                $violations[] = "[{$path}] Cannot read schema file: {$schemaFile}";

                return;
            }

            $schema = json_decode($schemaContents);
            if ($schema === null) {
                $violations[] = "[{$path}] Invalid schema JSON: {$schemaFile}";

                return;
            }

            // Validate context against schema
            $validator = new Validator();
            $contextObj = json_decode(json_encode($context));
            $validator->validate($contextObj, $schema);

            if (! $validator->isValid()) {
                foreach ($validator->getErrors() as $error) {
                    if (! is_array($error)) {
                        continue;
                    }

                    $property = is_string($error['property'] ?? '') ? $error['property'] : '';
                    $message = is_string($error['message'] ?? '') ? $error['message'] : 'Validation failed';
                    $violations[] = "[{$path}.context ({$type})] {$message} at '{$property}'";
                }
            } else {
                echo "✅ {$path} ({$type}) validates against {$schemaUrl}\n";
            }
        }

        // Recursively validate nested open/close
        if (isset($contextData['open']) && is_array($contextData['open'])) {
            $this->validateContext($contextData['open'], $schemaDir, "{$path}.open", $violations);
        }

        if (isset($contextData['close']) && is_array($contextData['close'])) {
            $this->validateContext($contextData['close'], $schemaDir, "{$path}.close", $violations);
        }
    }

    /**
     * Resolve schema URL to local file path
     */
    private function resolveSchemaPath(string $schemaUrl, string $schemaDir): string|null
    {
        // Handle relative paths like "./schemas/http_request.json"
        if (str_starts_with($schemaUrl, './schemas/')) {
            $filename = basename($schemaUrl);
            $schemaFile = realpath($schemaDir . '/' . $filename);

            return $schemaFile !== false ? $schemaFile : null;
        }

        // Handle direct filenames
        if (! str_contains($schemaUrl, '/')) {
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
