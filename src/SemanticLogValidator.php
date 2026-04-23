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
     * @param array<mixed> $data
     * @param list<string>        $violations
     * @param-out list<string>    $violations
     */
    private function validateContexts(array $data, string $schemaDir, array &$violations): void
    {
        if (isset($data['open']) && is_array($data['open'])) {
            /** @var array<int, mixed> $opens */
            $opens = $data['open'];
            foreach ($opens as $index => $open) {
                if (! is_array($open)) {
                    continue;
                }

                /** @var array<mixed> $open */
                $this->validateOpenEntry($open, $schemaDir, "open[{$index}]", $violations);
            }
        }

        if (isset($data['close']) && is_array($data['close'])) {
            /** @var array<int, mixed> $closes */
            $closes = $data['close'];
            foreach ($closes as $index => $close) {
                if (! is_array($close)) {
                    continue;
                }

                /** @var array<mixed> $close */
                $this->validateCloseEntry($close, $schemaDir, "close[{$index}]", $violations);
            }
        }

        if (isset($data['events']) && is_array($data['events'])) {
            /** @var array<int, mixed> $events */
            $events = $data['events'];
            foreach ($events as $index => $event) {
                if (! is_array($event)) {
                    continue;
                }

                /** @var array<mixed> $event */
                $this->validateEventEntry($event, $schemaDir, "events[{$index}]", $violations);
            }
        }
    }

    /**
     * Validate a single context against its schema
     *
     * @param array<mixed> $contextData
     * @param list<string>        $violations
     * @param-out list<string>    $violations
     */
    private function validateContext(array $contextData, string $schemaDir, string $path, array &$violations): void
    {
        $schemaUrl = $this->extractSchemaUrl($contextData);
        if ($schemaUrl !== null && isset($contextData['context'], $contextData['type'])) {
            $this->validateSingleContext($contextData, $schemaUrl, $schemaDir, $path, $violations);
        }
    }

    /**
     * @param array<mixed> $contextData
     * @param list<string>        $violations
     * @param-out list<string>    $violations
     */
    private function validateSingleContext(array $contextData, string $schemaUrl, string $schemaDir, string $path, array &$violations): void
    {
        $type = $contextData['type'];
        $context = $contextData['context'];

        if (! is_string($type) || ! is_array($context)) {
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

    /** @param array<mixed> $contextData */
    private function extractSchemaUrl(array $contextData): string|null
    {
        if (isset($contextData['$schema']) && is_string($contextData['$schema'])) {
            return $contextData['$schema'];
        }

        if (isset($contextData['schemaUrl']) && is_string($contextData['schemaUrl'])) {
            return $contextData['schemaUrl'];
        }

        return null;
    }

    /**
     * @param list<string>     $violations
     * @param-out list<string> $violations
     */
    private function loadSchema(string $schemaFile, string $path, array &$violations): object|null
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
     * @param array<mixed> $context
     * @param list<string>        $violations
     * @param-out list<string>    $violations
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
     * @param list<string>     $violations
     * @param-out list<string> $violations
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
     * @param array<mixed> $entry
     * @param list<string>        $violations
     * @param-out list<string>    $violations
     */
    private function validateOpenEntry(array $entry, string $schemaDir, string $path, array &$violations): void
    {
        $this->validateContext($entry, $schemaDir, $path, $violations);

        if (isset($entry['events']) && is_array($entry['events'])) {
            /** @var array<int, mixed> $events */
            $events = $entry['events'];
            foreach ($events as $index => $event) {
                if (! is_array($event)) {
                    continue;
                }

                /** @var array<mixed> $event */
                $this->validateEventEntry($event, $schemaDir, "{$path}.events[{$index}]", $violations);
            }
        }

        if (isset($entry['close']) && is_array($entry['close'])) {
            /** @var array<mixed> $close */
            $close = $entry['close'];
            $this->validateCloseEntry($close, $schemaDir, "{$path}.close", $violations);
        }

        if (isset($entry['open']) && is_array($entry['open'])) {
            /** @var array<int, mixed> $children */
            $children = $entry['open'];
            foreach ($children as $index => $child) {
                if (! is_array($child)) {
                    continue;
                }

                /** @var array<mixed> $child */
                $this->validateOpenEntry($child, $schemaDir, "{$path}.open[{$index}]", $violations);
            }
        }
    }

    /**
     * @param array<mixed> $entry
     * @param list<string>        $violations
     * @param-out list<string>    $violations
     */
    private function validateEventEntry(array $entry, string $schemaDir, string $path, array &$violations): void
    {
        $this->validateContext($entry, $schemaDir, $path, $violations);
    }

    /**
     * @param array<mixed> $entry
     * @param list<string>        $violations
     * @param-out list<string>    $violations
     */
    private function validateCloseEntry(array $entry, string $schemaDir, string $path, array &$violations): void
    {
        $this->validateContext($entry, $schemaDir, $path, $violations);

        if (isset($entry['close']) && is_array($entry['close'])) {
            /** @var array<int, mixed> $children */
            $children = $entry['close'];
            foreach ($children as $index => $child) {
                if (! is_array($child)) {
                    continue;
                }

                /** @var array<mixed> $child */
                $this->validateCloseEntry($child, $schemaDir, "{$path}.close[{$index}]", $violations);
            }
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
