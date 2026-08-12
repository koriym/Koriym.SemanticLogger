<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use InvalidArgumentException;
use JsonSchema\Validator;
use Override;
use RuntimeException;

use function array_push;
use function basename;
use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_object;
use function is_string;
use function json_decode;
use function property_exists;
use function realpath;
use function sprintf;
use function str_contains;
use function str_starts_with;

final class SemanticLogValidator implements SemanticLogValidatorInterface
{
    private ContextMetadataChecker $metadataChecker;
    private CoreDiagnostic $coreDiagnostic;

    public function __construct()
    {
        $this->metadataChecker = new ContextMetadataChecker();
        $this->coreDiagnostic = new CoreDiagnostic();
    }

    /** @SuppressWarnings("PHPMD.BooleanArgumentFlag") See the interface. */
    #[Override]
    public function validate(string $file, string $schemaDir, bool $failOnDiagnostics = false): void
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

        $logData = json_decode($contents);
        if ($logData === null) {
            throw new InvalidArgumentException("Invalid JSON in log file: {$file}");
        }

        $violations = [];
        $diagnostics = [];

        if (! is_object($logData)) {
            throw new InvalidArgumentException('Log data must be an object');
        }

        $this->validateRootSchema($logData, $violations);
        $this->validateContexts($logData, $schemaDir, $violations, $diagnostics);

        $this->coreDiagnostic->report($diagnostics);

        if (! empty($violations)) {
            $this->reportViolations($violations);

            throw new RuntimeException(sprintf('Validation failed with %d violations', count($violations)));
        }

        if ($failOnDiagnostics && $diagnostics !== []) {
            throw new RuntimeException(sprintf('The logger recorded %d diagnostic entries', count($diagnostics)));
        }

        echo "✅ All contexts validate successfully!\n";
    }

    /**
     * Extract and validate all contexts from log data
     *
     * @param list<string> $violations
     * @param list<string> $diagnostics
     *
     * @param-out list<string>    $violations
     * @param-out list<string>    $diagnostics
     */
    private function validateContexts(object $data, string $schemaDir, array &$violations, array &$diagnostics): void
    {
        if (isset($data->open) && is_array($data->open)) {
            /** @var array<int, mixed> $opens */
            $opens = $data->open;
            foreach ($opens as $index => $open) {
                if (! is_object($open)) {
                    continue;
                }

                $this->validateOpenEntry($open, $schemaDir, "open[{$index}]", $violations, $diagnostics);
            }
        }

        if (isset($data->close) && is_array($data->close)) {
            /** @var array<int, mixed> $closes */
            $closes = $data->close;
            foreach ($closes as $index => $close) {
                if (! is_object($close)) {
                    continue;
                }

                $this->validateCloseEntry($close, $schemaDir, "close[{$index}]", $violations, $diagnostics);
            }
        }

        if (isset($data->events) && is_array($data->events)) {
            /** @var array<int, mixed> $events */
            $events = $data->events;
            foreach ($events as $index => $event) {
                if (! is_object($event)) {
                    continue;
                }

                $this->validateEventEntry($event, $schemaDir, "events[{$index}]", $violations, $diagnostics);
            }
        }
    }

    /**
     * Validate a single context against its schema
     *
     * Logger-owned diagnostic entries are collected with their own severity and
     * validated against the schemas bundled with this package. User entries get
     * the static metadata checks the runtime deliberately does not enforce.
     *
     * @param list<string> $violations
     * @param list<string> $diagnostics
     *
     * @param-out list<string>    $violations
     * @param-out list<string>    $diagnostics
     */
    private function validateContext(object $contextData, string $schemaDir, string $path, array &$violations, array &$diagnostics): void
    {
        if (! isset($contextData->context, $contextData->type)) {
            return;
        }

        /** @var mixed $type */
        $type = $contextData->type;
        if (is_string($type) && $this->coreDiagnostic->isCoreType($type)) {
            $diagnostics[] = $this->coreDiagnostic->describe($contextData, $path, $type);
            $this->validateCoreDiagnostic($contextData, $path, $violations);

            return;
        }

        $metadataViolations = $this->metadataChecker->check($contextData, $path);
        if ($metadataViolations !== []) {
            array_push($violations, ...$metadataViolations);

            return;
        }

        $schemaUrl = $this->extractSchemaUrl($contextData);
        if ($schemaUrl !== null) {
            $this->validateSingleContext($contextData, $schemaUrl, $schemaDir, $path, $violations);
        }
    }

    /**
     * Core diagnostic entries are validated against the schemas bundled with
     * this package, not against the application's schema directory.
     *
     * @param list<string> $violations
     *
     * @param-out list<string>    $violations
     */
    private function validateCoreDiagnostic(object $entry, string $path, array &$violations): void
    {
        $schemaUrl = $this->extractSchemaUrl($entry);
        $filename = $schemaUrl !== null ? basename($schemaUrl) : '';
        $schemaFile = dirname(__DIR__) . '/docs/schemas/' . $filename;
        if (! file_exists($schemaFile)) {
            $violations[] = "[{$path}] Bundled core schema not found: {$filename}";

            return;
        }

        $schema = $this->loadSchema($schemaFile, $path, $violations);
        if ($schema === null) {
            return;
        }

        $context = $entry->context ?? null;
        if (! is_object($context)) {
            $violations[] = "[{$path}] Invalid context structure";

            return;
        }

        $type = isset($entry->type) && is_string($entry->type) ? $entry->type : '';
        $this->performValidation($context, $schema, $type, (string) $schemaUrl, $path, $violations);
    }

    /**
     * @param list<string> $violations
     *
     * @param-out list<string>    $violations
     */
    private function validateSingleContext(object $contextData, string $schemaUrl, string $schemaDir, string $path, array &$violations): void
    {
        if (! property_exists($contextData, 'type') || ! property_exists($contextData, 'context')) {
            $violations[] = "[{$path}] Invalid context structure";

            return;
        }

        $type = $contextData->type;
        $context = $contextData->context;

        if (! is_string($type) || ! is_object($context)) {
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

    private function extractSchemaUrl(object $contextData): string|null
    {
        if (property_exists($contextData, '$schema') && is_string($contextData->{'$schema'})) {
            return $contextData->{'$schema'};
        }

        if (isset($contextData->schemaUrl) && is_string($contextData->schemaUrl)) {
            return $contextData->schemaUrl;
        }

        return null;
    }

    /**
     * @param list<string> $violations
     *
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
     * @param list<string> $violations
     *
     * @param-out list<string>    $violations
     */
    private function performValidation(object $context, object $schema, string $type, string $schemaUrl, string $path, array &$violations): void
    {
        $validator = new Validator();
        $validator->validate($context, $schema);

        if (! $validator->isValid()) {
            $this->addValidationErrors($validator, $type, $path, $violations);

            return;
        }

        echo "✅ {$path} ({$type}) validates against {$schemaUrl}\n";
    }

    /**
     * @param list<string> $violations
     *
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
     * @param list<string> $violations
     *
     * @param-out list<string> $violations
     */
    private function validateRootSchema(object $logData, array &$violations): void
    {
        $schemaFile = dirname(__DIR__) . '/docs/schemas/semantic-log.json';
        if (! file_exists($schemaFile)) {
            throw new RuntimeException("Bundled semantic-log schema not found: {$schemaFile}");
        }

        $schema = $this->loadSchema($schemaFile, 'root', $violations);
        if ($schema === null) {
            return;
        }

        $validator = new Validator();
        $validator->validate($logData, $schema);

        if ($validator->isValid()) {
            return;
        }

        foreach ($validator->getErrors() as $error) {
            if (! is_array($error)) {
                continue;
            }

            $property = isset($error['property']) && is_string($error['property']) ? $error['property'] : '';
            $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : 'Validation failed';
            $violations[] = "[root] {$message} at '{$property}'";
        }
    }

    /**
     * @param list<string> $violations
     * @param list<string> $diagnostics
     *
     * @param-out list<string>    $violations
     * @param-out list<string>    $diagnostics
     */
    private function validateOpenEntry(object $entry, string $schemaDir, string $path, array &$violations, array &$diagnostics): void
    {
        $this->validateContext($entry, $schemaDir, $path, $violations, $diagnostics);

        if (isset($entry->events) && is_array($entry->events)) {
            /** @var array<int, mixed> $events */
            $events = $entry->events;
            foreach ($events as $index => $event) {
                if (! is_object($event)) {
                    continue;
                }

                $this->validateEventEntry($event, $schemaDir, "{$path}.events[{$index}]", $violations, $diagnostics);
            }
        }

        if (isset($entry->close) && is_object($entry->close)) {
            $close = $entry->close;
            $this->validateCloseEntry($close, $schemaDir, "{$path}.close", $violations, $diagnostics);
        }

        if (isset($entry->open) && is_array($entry->open)) {
            /** @var array<int, mixed> $children */
            $children = $entry->open;
            foreach ($children as $index => $child) {
                if (! is_object($child)) {
                    continue;
                }

                $this->validateOpenEntry($child, $schemaDir, "{$path}.open[{$index}]", $violations, $diagnostics);
            }
        }
    }

    /**
     * @param list<string> $violations
     * @param list<string> $diagnostics
     *
     * @param-out list<string>    $violations
     * @param-out list<string>    $diagnostics
     */
    private function validateEventEntry(object $entry, string $schemaDir, string $path, array &$violations, array &$diagnostics): void
    {
        $this->validateContext($entry, $schemaDir, $path, $violations, $diagnostics);
    }

    /**
     * @param list<string> $violations
     * @param list<string> $diagnostics
     *
     * @param-out list<string>    $violations
     * @param-out list<string>    $diagnostics
     */
    private function validateCloseEntry(object $entry, string $schemaDir, string $path, array &$violations, array &$diagnostics): void
    {
        $this->validateContext($entry, $schemaDir, $path, $violations, $diagnostics);
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
