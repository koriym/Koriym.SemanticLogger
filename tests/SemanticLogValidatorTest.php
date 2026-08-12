<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class SemanticLogValidatorTest extends TestCase
{
    private string $fixtureDirectory = '';
    private string $schemaDirectory = '';
    private string $logFile = '';
    private SemanticLogValidator|null $validator = null;

    #[Override]
    protected function setUp(): void
    {
        $this->fixtureDirectory = sys_get_temp_dir() . '/semantic-log-validator-' . uniqid('', true);
        $this->schemaDirectory = $this->fixtureDirectory . '/schemas';
        $this->logFile = $this->fixtureDirectory . '/log.json';
        mkdir($this->schemaDirectory, 0777, true);
        $this->validator = new SemanticLogValidator();

        file_put_contents(
            $this->schemaDirectory . '/being-final-open.json',
            <<<'JSON'
{
  "type": "object",
  "required": ["from", "final", "input", "inject"],
  "properties": {
    "from": { "type": "string" },
    "final": { "type": "string" },
    "input": {
      "type": "object",
      "additionalProperties": { "type": "string" }
    },
    "inject": {
      "type": "object",
      "additionalProperties": { "type": "string" }
    }
  },
  "additionalProperties": false
}
JSON,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_dir($this->schemaDirectory)) {
            unlink($this->schemaDirectory . '/being-final-open.json');
            rmdir($this->schemaDirectory);
        }

        if (is_dir($this->fixtureDirectory)) {
            if (file_exists($this->logFile)) {
                unlink($this->logFile);
            }

            rmdir($this->fixtureDirectory);
        }
    }

    public function testValidateAcceptsEmptyObjectMapsInContext(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "open": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      }
    }
  ]
}
JSON,
        );

        $this->expectOutputRegex('/All contexts validate successfully/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsMissingRootSchemaField(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "open": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      }
    }
  ]
}
JSON,
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/\$schema/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsInvalidOperationIdPattern(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "open": [
    {
      "id": "bad-id",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      }
    }
  ]
}
JSON,
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/open\[0\]\.id|regex pattern/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsInvalidContextType(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "open": [
    {
      "id": "being_final_open_1",
      "type": "InvalidType",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      }
    }
  ]
}
JSON,
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/Invalid context type: InvalidType/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsReservedTypePrefix(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "events": [
    {
      "id": "semantic_logger_fake_1",
      "type": "semantic_logger_fake",
      "schemaUrl": "https://example.com/schemas/fake.json",
      "context": { "message": "user context squatting on the core namespace" }
    }
  ]
}
JSON,
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/Invalid context type: semantic_logger_fake/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsInvalidSchemaUrl(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "open": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "not-a-url",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      }
    }
  ]
}
JSON,
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/Invalid context schemaUrl: not-a-url/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateAcceptsRelativeSchemaUrl(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "open": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "./schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      }
    }
  ]
}
JSON,
        );

        $this->expectOutputRegex('/All contexts validate successfully/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateListsDiagnosticsWithoutFailing(): void
    {
        $this->writeLogWithDiagnosticEvent();

        $this->expectOutputRegex('/recorded 1 diagnostic entries[\s\S]*All contexts validate successfully/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateFailsOnDiagnosticsWhenRequested(): void
    {
        $this->writeLogWithDiagnosticEvent();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The logger recorded 1 diagnostic entries');
        $this->expectOutputRegex('/recorded 1 diagnostic entries/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory, true);
    }

    public function testValidateRejectsInvalidCoreDiagnosticContext(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "events": [
    {
      "id": "semantic_logger_error_1",
      "type": "semantic_logger_error",
      "schemaUrl": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-logger-error.json",
      "context": {
        "kind": "bogus_kind",
        "message": "Not a real diagnostic kind."
      }
    }
  ]
}
JSON,
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/semantic_logger_error/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateListsInvalidContextPlaceholder(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "events": [
    {
      "id": "semantic_logger_invalid_context_1",
      "type": "semantic_logger_invalid_context",
      "schemaUrl": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-logger-invalid-context.json",
      "context": {
        "operation": "event",
        "errors": [
          {
            "kind": "context_serialization_failed",
            "message": "Context serialization failed."
          }
        ],
        "originalType": "broken_context",
        "originalSchemaUrl": "https://example.com/schemas/broken.json"
      }
    }
  ]
}
JSON,
        );

        $this->expectOutputRegex('/semantic_logger_invalid_context \(context_serialization_failed\)[\s\S]*All contexts validate successfully/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsCoreDiagnosticWithCrossTypeSchemaUrl(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "events": [
    {
      "id": "semantic_logger_error_1",
      "type": "semantic_logger_error",
      "schemaUrl": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-logger-invalid-context.json",
      "context": {
        "kind": "close_without_open",
        "message": "Close called without a matching open.",
        "relatedId": "missing_open_1"
      }
    }
  ]
}
JSON,
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/canonical schema URL/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsCoreDiagnosticWithNoncanonicalSchemaUrl(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "events": [
    {
      "id": "semantic_logger_error_1",
      "type": "semantic_logger_error",
      "schemaUrl": "https://example.com/schemas/semantic-logger-error.json",
      "context": {
        "kind": "close_without_open",
        "message": "Close called without a matching open.",
        "relatedId": "missing_open_1"
      }
    }
  ]
}
JSON,
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/canonical schema URL/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    private function writeLogWithDiagnosticEvent(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "open": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      }
    }
  ],
  "events": [
    {
      "id": "semantic_logger_error_1",
      "type": "semantic_logger_error",
      "schemaUrl": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-logger-error.json",
      "context": {
        "kind": "close_without_open",
        "message": "Close called without a matching open.",
        "relatedId": "missing_open_1"
      }
    }
  ]
}
JSON,
        );
    }
}
