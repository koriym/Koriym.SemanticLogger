<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use InvalidArgumentException;
use JsonSchema\Validator;
use Override;
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
    #[Override]
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
     * @param array<mixed, mixed> $data
     * @param array<string>       $violations
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
            /** @var array<int, mixed> $events */
            $events = $data['events'];
            /** @psalm-suppress MixedAssignment */
            foreach ($events as $index => $event) {
                if (is_array($event)) {
                    /** @var array<mixed, mixed> $eventData */
                    $eventData = $event;
                    $this->validateContext($eventData, $schemaDir, "events[{$index}]", $violations);
                }
            }
        }
    }

    /**
     * Validate a single context against its schema
     *
     * @param array<mixed, mixed> $contextData
     * @param array<string>       $violations
     */
    private function validateContext(array $contextData, string $schemaDir, string $path, array &$violations): void
    {
        // Validate current context if it has required fields
        if (isset($contextData['schemaUrl'], $contextData['context'], $contextData['type'])) {
            $this->validateSingleContext($contextData, $schemaDir, $path, $violations);
        }

        // Recursively validate nested structures
        $this->validateNestedContexts($contextData, $schemaDir, $path, $violations);
    }

    /**
     * @param array<mixed, mixed> $contextData
     * @param array<string>       $violations
     */
    private function validateSingleContext(array $contextData, string $schemaDir, string $path, array &$violations): void
    {
        $schemaUrl = $contextData['schemaUrl'];
        $type = $contextData['type'];
        $context = $contextData['context'];

        if (! is_string($schemaUrl) || ! is_string($type) || ! is_array($context)) {
            $violations[] = "[{$path}] Invalid context structure";
            return;
        }

        $schemaFile = $this->resolveSchemaPath($schemaUrl, $schemaDir);
        if ($schemaFile === null) {
            $violations[] = "[{$path}] Schema file not found: {$schemaUrl}";
            return;
        }

        $schema = $this->loadSchema($schemaFile, $path, $violations);
        if ($schema === null) {
            return;
        }

        $this->performValidation($context, $schema, $type, $schemaUrl, $path, $violations);
    }

    /**
     * @param array<string>       $violations
     */
    private function loadSchema(string $schemaFile, string $path, array &$violations): ?object
    {
        $schemaContents = file_get_contents($schemaFile);
        if ($schemaContents === false) {
            $violations[] = "[{$path}] Cannot read schema file: {$schemaFile}";
            return null;
        }

        /** @var object|null $schema */
        $schema = json_decode($schemaContents);
        if ($schema === null) {
            $violations[] = "[{$path}] Invalid schema JSON: {$schemaFile}";
            return null;
        }

        return $schema;
    }

    /**
     * @param array<mixed, mixed> $context
     * @param array<string>       $violations
     */
    private function performValidation(array $context, object $schema, string $type, string $schemaUrl, string $path, array &$violations): void
    {
        $validator = new Validator();
        $contextJson = json_encode($context);
        if ($contextJson === false) {
            $violations[] = "[{$path}] Failed to encode context to JSON";
            return;
        }

        /** @var object|null $contextObj */
        $contextObj = json_decode($contextJson);
        $validator->validate($contextObj, $schema);

        if (! $validator->isValid()) {
            $this->addValidationErrors($validator, $type, $path, $violations);
            return;
        }

        echo "✅ {$path} ({$type}) validates against {$schemaUrl}\n";
    }

    /**
     * @param array<string> $violations
     */
    private function addValidationErrors(Validator $validator, string $type, string $path, array &$violations): void
    {
        foreach ($validator->getErrors() as $error) {
            if (! is_array($error)) {
                continue;
            }

            $property = isset($error['property']) && is_string($error['property']) ? $error['property'] : '';
            $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : 'Validation failed';
            $violations[] = "[{$path}.context ({$type})] {$message} at '{$property}'";
        }
    }

    /**
     * @param array<mixed, mixed> $contextData
     * @param array<string>       $violations
     */
    private function validateNestedContexts(array $contextData, string $schemaDir, string $path, array &$violations): void
    {
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
