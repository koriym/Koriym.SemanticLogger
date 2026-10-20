<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function file_exists;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class SemanticLogValidatorTest extends TestCase
{
    private const ROOT_SCHEMA = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';

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
        file_put_contents(
            $this->schemaDirectory . '/being-final-close.json',
            '{"type":"object","required":["status"],"properties":{"status":{"type":"string"}},"additionalProperties":false}',
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_dir($this->schemaDirectory)) {
            unlink($this->schemaDirectory . '/being-final-open.json');
            unlink($this->schemaDirectory . '/being-final-close.json');
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
  "entries": [
    {
      "kind": "open",
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "children": []
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
  "entries": [
    {
      "kind": "open",
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "children": []
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
  "entries": [
    {
      "kind": "open",
      "id": "bad-id",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "children": []
    }
  ]
}
JSON,
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/entries\[0\]\.id|regex pattern/');
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
  "entries": [
    {
      "kind": "open",
      "id": "being_final_open_1",
      "type": "InvalidType",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "children": []
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
  "entries": [
    {
      "kind": "event",
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
  "entries": [
    {
      "kind": "open",
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "not-a-url",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "children": []
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
  "entries": [
    {
      "kind": "open",
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "./schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "children": []
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
  "entries": [
    {
      "kind": "event",
      "id": "semantic_logger_error_1",
      "type": "semantic_logger_error",
      "schemaUrl": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-logger-error.json",
      "context": {
        "code": "bogus_kind",
        "message": "Not a real diagnostic code."
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
  "entries": [
    {
      "kind": "event",
      "id": "semantic_logger_invalid_context_1",
      "type": "semantic_logger_invalid_context",
      "schemaUrl": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-logger-invalid-context.json",
      "context": {
        "operation": "event",
        "errors": [
          {
            "code": "context_serialization_failed",
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
  "entries": [
    {
      "kind": "event",
      "id": "semantic_logger_error_1",
      "type": "semantic_logger_error",
      "schemaUrl": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-logger-invalid-context.json",
      "context": {
        "code": "close_without_open",
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
  "entries": [
    {
      "kind": "event",
      "id": "semantic_logger_error_1",
      "type": "semantic_logger_error",
      "schemaUrl": "https://example.com/schemas/semantic-logger-error.json",
      "context": {
        "code": "close_without_open",
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

    public function testValidateAcceptsNestedChildrenAndClose(): void
    {
        $child = $this->openEntry(['id' => 'being_final_open_2', 'close' => $this->closeEntry(['id' => 'being_final_close_3'])]);
        $this->writeLog(['entries' => [$this->openEntry(['children' => [$child], 'close' => $this->closeEntry()])]]);

        $this->expectOutputRegex('/entries\[0\]\.children\[0\]\.close \(being_final_close\)[\s\S]*entries\[0\]\.close \(being_final_close\)[\s\S]*All contexts validate successfully/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsInvalidNestedChildContext(): void
    {
        $event = $this->openEntry(['kind' => 'event', 'id' => 'being_final_open_2', 'context' => ['from' => 'A']]);
        unset($event['children']);
        $this->writeLog(['entries' => [$this->openEntry(['children' => [$event]])]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/\[entries\[0\]\.children\[0\]\.context \(being_final_open\)\]/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsInvalidNestedCloseContext(): void
    {
        $this->writeLog(['entries' => [$this->openEntry(['close' => $this->closeEntry(['context' => ['status' => 1]])])]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/\[entries\[0\]\.close\.context \(being_final_close\)\]/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateAcceptsOrphanClose(): void
    {
        $this->writeLog(['entries' => [], 'close' => [$this->closeEntry(['openId' => 'missing_open_1'])]]);

        $this->expectOutputRegex('/close\[0\] \(being_final_close\)[\s\S]*All contexts validate successfully/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsInvalidOrphanCloseContext(): void
    {
        $this->writeLog(['entries' => [], 'close' => [$this->closeEntry(['openId' => 'missing_open_1', 'context' => ['status' => 1]])]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/\[close\[0\]\.context \(being_final_close\)\]/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsMissingContextSchema(): void
    {
        $this->writeLog(['entries' => [$this->openEntry(['schemaUrl' => 'https://example.com/schemas/unknown.json'])]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/\[entries\[0\]\] Schema file not found: https:\/\/example\.com\/schemas\/unknown\.json/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsMissingEntries(): void
    {
        $this->writeLog([]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/entries/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsOpenEntryWithoutChildren(): void
    {
        $open = $this->openEntry();
        unset($open['children']);
        $this->writeLog(['entries' => [$open]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/\[root\]/');
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
  "entries": [
    {
      "kind": "open",
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "children": [
        {
          "kind": "event",
          "id": "semantic_logger_error_1",
          "type": "semantic_logger_error",
          "schemaUrl": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-logger-error.json",
          "context": {
            "code": "close_without_open",
            "message": "Close called without a matching open.",
            "relatedId": "missing_open_1"
          }
        }
      ]
    }
  ]
}
JSON,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function openEntry(array $overrides = []): array
    {
        return $overrides + [
            'kind' => 'open',
            'id' => 'being_final_open_1',
            'type' => 'being_final_open',
            'schemaUrl' => 'https://be-framework.org/schemas/being-final-open.json',
            'context' => ['from' => 'A', 'final' => 'B', 'input' => new stdClass(), 'inject' => new stdClass()],
            'children' => [],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function closeEntry(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'being_final_close_2',
            'type' => 'being_final_close',
            'schemaUrl' => 'https://be-framework.org/schemas/being-final-close.json',
            'context' => ['status' => 'done'],
        ];
    }

    /** @param array<string, mixed> $log */
    private function writeLog(array $log): void
    {
        file_put_contents($this->logFile, json_encode(['$schema' => self::ROOT_SCHEMA] + $log));
    }
}
