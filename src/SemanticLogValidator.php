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
use function sprintf;

final class SemanticLogValidator implements SemanticLogValidatorInterface
{
    private readonly ContextMetadataChecker $metadataChecker;
    private readonly CoreDiagnostic $coreDiagnostic;

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
        $warnings = [];

        if (! is_object($logData)) {
            throw new InvalidArgumentException('Log data must be an object');
        }

        $schemaResolver = new SchemaResolver();

        $this->validateRootSchema($logData, $schemaResolver, $violations);
        $this->validateLog($logData, $schemaDir, $schemaResolver, $violations, $diagnostics, $warnings);

        $this->coreDiagnostic->report($diagnostics);
        $this->reportWarnings($warnings);

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
     * Walk the root `log` list, resolving schemas and checking structural
     * invariants (`id` uniqueness, type→schema consistency, orphan matching,
     * interrupted intervals) that JSON Schema cannot express.
     *
     * @param list<string> $violations
     * @param list<string> $diagnostics
     * @param list<string> $warnings
     *
     * @param-out list<string>    $violations
     * @param-out list<string>    $diagnostics
     * @param-out list<string>    $warnings
     */
    private function validateLog(object $data, string $schemaDir, SchemaResolver $schemaResolver, array &$violations, array &$diagnostics, array &$warnings): void
    {
        if (! isset($data->log) || ! is_array($data->log)) {
            return;
        }

        /** @var array<array-key, mixed> $log */
        $log = $data->log;

        /** @var array<string, string> $types */
        $types = $schemaResolver->extractTypesMap($data);

        /** @var array<string, bool> $recordIds */
        $recordIds = [];
        $this->collectRecordIds($log, $recordIds);

        /** @var array<string, string> $seenIds */
        $seenIds = [];

        $this->validateRootRecords($log, $schemaDir, $types, $schemaResolver, $seenIds, $recordIds, $violations, $diagnostics, $warnings);
    }

    /**
     * Collect the ids of every record (interval/point, not orphan closes) in
     * the tree, so a root orphan's `openId` can be checked against the whole
     * document, not just the root scope.
     *
     * @param array<array-key, mixed> $records
     * @param array<string, bool>     $recordIds
     *
     * @param-out array<string, bool> $recordIds
     */
    private function collectRecordIds(array $records, array &$recordIds): void
    {
        foreach ($records as $record) {
            if (! is_object($record) || isset($record->openId)) {
                continue;
            }

            if (isset($record->id) && is_string($record->id)) {
                $recordIds[$record->id] = true;
            }

            if (isset($record->log) && is_array($record->log)) {
                $this->collectRecordIds($record->log, $recordIds);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $records
     * @param array<string, string>   $types
     * @param array<string, string>   $seenIds
     * @param array<string, bool>     $recordIds
     * @param list<string>            $violations
     * @param list<string>            $diagnostics
     * @param list<string>            $warnings
     *
     * @param-out array<string, string> $seenIds
     * @param-out list<string>          $violations
     * @param-out list<string>          $diagnostics
     * @param-out list<string>          $warnings
     */
    private function validateRootRecords(array $records, string $schemaDir, array $types, SchemaResolver $schemaResolver, array &$seenIds, array $recordIds, array &$violations, array &$diagnostics, array &$warnings): void
    {
        foreach ($records as $index => $record) {
            if (! is_object($record)) {
                continue;
            }

            $path = "log[{$index}]";

            if (isset($record->openId)) {
                $this->validateOrphanClose($record, $schemaDir, $types, $schemaResolver, $seenIds, $recordIds, $path, $violations, $diagnostics);

                continue;
            }

            $this->validateRecord($record, $schemaDir, $types, $schemaResolver, $seenIds, $path, $violations, $diagnostics, $warnings);
        }
    }

    /**
     * @param array<array-key, mixed> $records
     * @param array<string, string>   $types
     * @param array<string, string>   $seenIds
     * @param list<string>            $violations
     * @param list<string>            $diagnostics
     * @param list<string>            $warnings
     *
     * @param-out array<string, string> $seenIds
     * @param-out list<string>          $violations
     * @param-out list<string>          $diagnostics
     * @param-out list<string>          $warnings
     */
    private function validateNestedRecords(array $records, string $schemaDir, array $types, SchemaResolver $schemaResolver, array &$seenIds, string $path, array &$violations, array &$diagnostics, array &$warnings): void
    {
        foreach ($records as $index => $record) {
            if (! is_object($record)) {
                continue;
            }

            $this->validateRecord($record, $schemaDir, $types, $schemaResolver, $seenIds, "{$path}[{$index}]", $violations, $diagnostics, $warnings);
        }
    }

    /**
     * Validate one record (completed interval, interrupted interval, or
     * point) and recurse into its `log`/`close`.
     *
     * @param array<string, string> $types
     * @param array<string, string> $seenIds
     * @param list<string>          $violations
     * @param list<string>          $diagnostics
     * @param list<string>          $warnings
     *
     * @param-out array<string, string> $seenIds
     * @param-out list<string>          $violations
     * @param-out list<string>          $diagnostics
     * @param-out list<string>          $warnings
     */
    private function validateRecord(object $record, string $schemaDir, array $types, SchemaResolver $schemaResolver, array &$seenIds, string $path, array &$violations, array &$diagnostics, array &$warnings): void
    {
        $kind = $this->recordKind($record);

        $this->checkId($record, $seenIds, $kind, $path, $violations);
        $this->validateContext($record, $kind, $schemaDir, $types, $schemaResolver, $path, $violations, $diagnostics);

        $hasLog = isset($record->log) && is_array($record->log);
        $hasClose = isset($record->close) && is_object($record->close);

        if ($hasLog && ! $hasClose) {
            $warnings[] = "interval {$path}: unclosed (log present without a matching close)";
        }

        if ($hasLog) {
            /** @var array<array-key, mixed> $nested */
            $nested = $record->log;
            $this->validateNestedRecords($nested, $schemaDir, $types, $schemaResolver, $seenIds, "{$path}.log", $violations, $diagnostics, $warnings);
        }

        if (! $hasClose) {
            return;
        }

        $closePath = "{$path}.close";
        /** @var object $close */
        $close = $record->close;
        $this->checkId($close, $seenIds, 'close', $closePath, $violations);
        $this->validateContext($close, 'close', $schemaDir, $types, $schemaResolver, $closePath, $violations, $diagnostics);
    }

    /**
     * Validate a root orphan close and check whether its `openId` names a
     * record that exists in this document — a close that the writer would
     * have matched, so an orphan naming it is a double close.
     *
     * @param array<string, string> $types
     * @param array<string, string> $seenIds
     * @param array<string, bool>   $recordIds
     * @param list<string>          $violations
     * @param list<string>          $diagnostics
     *
     * @param-out array<string, string> $seenIds
     * @param-out list<string>          $violations
     * @param-out list<string>          $diagnostics
     */
    private function validateOrphanClose(object $orphan, string $schemaDir, array $types, SchemaResolver $schemaResolver, array &$seenIds, array $recordIds, string $path, array &$violations, array &$diagnostics): void
    {
        $this->checkId($orphan, $seenIds, 'orphan close', $path, $violations);
        $this->validateContext($orphan, 'orphan close', $schemaDir, $types, $schemaResolver, $path, $violations, $diagnostics);

        /** @var mixed $openId */
        $openId = $orphan->openId ?? null;
        if (is_string($openId) && isset($recordIds[$openId])) {
            $violations[] = "orphan close {$path}: openId '{$openId}' names a record in this document: double close";
        }
    }

    /** A record is an interval when it carries `close` or `log` (even empty); otherwise it is a point. */
    private function recordKind(object $record): string
    {
        if (isset($record->close) || (isset($record->log) && is_array($record->log))) {
            return 'interval';
        }

        return 'point';
    }

    /**
     * @param array<string, string> $seenIds
     * @param list<string>          $violations
     *
     * @param-out array<string, string> $seenIds
     * @param-out list<string>          $violations
     */
    private function checkId(object $entry, array &$seenIds, string $kind, string $path, array &$violations): void
    {
        if (! isset($entry->id) || ! is_string($entry->id)) {
            return;
        }

        $id = $entry->id;
        if (isset($seenIds[$id])) {
            $violations[] = "{$kind} {$path}: duplicate id '{$id}' (already used at {$seenIds[$id]})";

            return;
        }

        $seenIds[$id] = $path;
    }

    /**
     * Validate a single context against its schema.
     *
     * Logger-owned diagnostic entries are collected with their own severity and
     * validated against the schemas bundled with this package. User entries get
     * the static metadata checks the runtime deliberately does not enforce.
     *
     * @param array<string, string> $types
     * @param list<string>          $violations
     * @param list<string>          $diagnostics
     *
     * @param-out list<string>          $violations
     * @param-out list<string>          $diagnostics
     */
    private function validateContext(object $contextData, string $kind, string $schemaDir, array $types, SchemaResolver $schemaResolver, string $path, array &$violations, array &$diagnostics): void
    {
        if (! isset($contextData->context, $contextData->type)) {
            return;
        }

        /** @var mixed $type */
        $type = $contextData->type;
        if (is_string($type) && $this->coreDiagnostic->isCoreType($type)) {
            $diagnostics[] = $this->coreDiagnostic->describe($contextData, $path, $type);
            $this->validateCoreDiagnostic($contextData, $type, $kind, $path, $violations, $schemaResolver);

            return;
        }

        if (! is_string($type)) {
            $metadataViolations = $this->metadataChecker->check($contextData, $path);
            array_push($violations, ...$metadataViolations);

            return;
        }

        $schemaUrl = $schemaResolver->resolveSchemaUrl($contextData, $type, $types, $kind, $path, $violations);
        if ($schemaUrl === null) {
            return;
        }

        // Metadata checks (type pattern, schemaUrl format) run against the
        // resolved schema URL, so a compact-form record (schema only known
        // through document-level `types`) is checked the same as one that
        // carries its own `schemaUrl`.
        $metadataViolations = $this->metadataChecker->check($schemaResolver->withResolvedSchemaUrl($contextData, $schemaUrl), $path);
        if ($metadataViolations !== []) {
            array_push($violations, ...$metadataViolations);

            return;
        }

        $this->validateSingleContext($contextData, $schemaUrl, $schemaDir, $kind, $path, $violations, $schemaResolver);
    }

    /**
     * Core diagnostic entries are validated against the schemas bundled with
     * this package, selected by the canonical type-to-URL mapping — an entry
     * whose URL disagrees with its core type is rejected before any schema load.
     *
     * @param object       $entry      the core-owned diagnostic entry
     * @param string       $type       the entry's core-owned type
     * @param list<string> $violations
     *
     * @param-out list<string>    $violations
     */
    private function validateCoreDiagnostic(object $entry, string $type, string $kind, string $path, array &$violations, SchemaResolver $schemaResolver): void
    {
        $canonicalUrl = $this->coreDiagnostic->schemaUrlFor($type);
        $schemaUrl = $schemaResolver->extractSchemaUrl($entry);
        if ($canonicalUrl === null || $schemaUrl !== $canonicalUrl) {
            $violations[] = "{$kind} {$path}: core diagnostic '{$type}' must use the canonical schema URL: {$canonicalUrl}";

            return;
        }

        $filename = basename($canonicalUrl);
        $schemaFile = dirname(__DIR__) . '/docs/schemas/' . $filename;
        if (! file_exists($schemaFile)) {
            $violations[] = "{$kind} {$path}: bundled core schema not found: {$filename}";

            return;
        }

        $schema = $schemaResolver->loadSchema($schemaFile, $kind, $path, $violations);
        if ($schema === null) {
            return;
        }

        $context = $entry->context ?? null;
        if (! is_object($context)) {
            $violations[] = "{$kind} {$path}: invalid context structure";

            return;
        }

        $type = isset($entry->type) && is_string($entry->type) ? $entry->type : '';
        $this->performValidation($context, $schema, $type, (string) $schemaUrl, $kind, $path, $violations);
    }

    /**
     * @param list<string> $violations
     *
     * @param-out list<string>    $violations
     */
    private function validateSingleContext(object $contextData, string $schemaUrl, string $schemaDir, string $kind, string $path, array &$violations, SchemaResolver $schemaResolver): void
    {
        if (! property_exists($contextData, 'type') || ! property_exists($contextData, 'context')) {
            $violations[] = "{$kind} {$path}: invalid context structure";

            return;
        }

        $type = $contextData->type;
        $context = $contextData->context;

        if (! is_string($type) || ! is_object($context)) {
            $violations[] = "{$kind} {$path}: invalid context structure";

            return;
        }

        $schemaFile = $schemaResolver->resolveSchemaPath($schemaUrl, $schemaDir);
        if ($schemaFile === null) {
            $violations[] = "{$kind} {$path}: schema file not found: {$schemaUrl}";

            return;
        }

        $schema = $schemaResolver->loadSchema($schemaFile, $kind, $path, $violations);
        if ($schema === null) {
            return;
        }

        $this->performValidation($context, $schema, $type, $schemaUrl, $kind, $path, $violations);
    }

    /**
     * @param list<string> $violations
     *
     * @param-out list<string>    $violations
     */
    private function performValidation(object $context, object $schema, string $type, string $schemaUrl, string $kind, string $path, array &$violations): void
    {
        $validator = new Validator();
        $validator->validate($context, $schema);

        if (! $validator->isValid()) {
            $this->addValidationErrors($validator, $type, $kind, $path, $violations);

            return;
        }

        echo "✅ {$path} ({$type}) validates against {$schemaUrl}\n";
    }

    /**
     * @param list<string> $violations
     *
     * @param-out list<string> $violations
     */
    private function addValidationErrors(Validator $validator, string $type, string $kind, string $path, array &$violations): void
    {
        foreach ($validator->getErrors() as $error) {
            if (! is_array($error)) {
                continue;
            }

            $property = isset($error['property']) && is_string($error['property']) ? $error['property'] : '';
            $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : 'Validation failed';
            $violations[] = "{$kind} {$path}.context ({$type}): {$message} at '{$property}'";
        }
    }

    /**
     * @param list<string> $violations
     *
     * @param-out list<string> $violations
     */
    private function validateRootSchema(object $logData, SchemaResolver $schemaResolver, array &$violations): void
    {
        $schemaFile = dirname(__DIR__) . '/docs/schemas/semantic-log.json';
        if (! file_exists($schemaFile)) {
            throw new RuntimeException("Bundled semantic-log schema not found: {$schemaFile}");
        }

        $schema = $schemaResolver->loadSchema($schemaFile, 'root', 'root', $violations);
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
            $violations[] = "root: {$message} at '{$property}'";
        }
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

    /**
     * Report validator-level warnings (e.g. interrupted intervals). Unlike
     * logger-recorded diagnostics, warnings never fail validation and are not
     * affected by `--fail-on-diagnostics`.
     *
     * @param list<string> $warnings
     */
    private function reportWarnings(array $warnings): void
    {
        if ($warnings === []) {
            return;
        }

        echo "⚠️  Warnings:\n";
        foreach ($warnings as $warning) {
            echo "  {$warning}\n";
        }
    }
}
