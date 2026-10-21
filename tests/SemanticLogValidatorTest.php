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
  "log": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "log": []
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
  "log": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "log": []
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
  "log": [
    {
      "id": "bad-id",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "log": []
    }
  ]
}
JSON,
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/log\[0\]\.id|regex pattern/');
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
  "log": [
    {
      "id": "being_final_open_1",
      "type": "InvalidType",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "log": []
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
  "log": [
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
  "log": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "not-a-url",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "log": []
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
  "log": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "./schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "log": []
    }
  ]
}
JSON,
        );

        $this->expectOutputRegex('/All contexts validate successfully/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateAcceptsCompactFormWithTypesIndex(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "types": {
    "being_final_open": "https://be-framework.org/schemas/being-final-open.json",
    "being_final_close": "https://be-framework.org/schemas/being-final-close.json"
  },
  "log": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "close": {
        "id": "being_final_close_2",
        "type": "being_final_close",
        "context": { "status": "done" }
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

    public function testValidateRejectsSchemaUrlDisagreeingWithTypesIndex(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "types": {
    "being_final_open": "https://be-framework.org/schemas/being-final-open.json"
  },
  "log": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "https://example.com/schemas/different.json",
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
        $this->expectOutputRegex('/point log\[0\]: schemaUrl .+ disagrees with types\[.being_final_open.\]/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsMissingSchemaResolution(): void
    {
        file_put_contents(
            $this->logFile,
            <<<'JSON'
{
  "$schema": "https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json",
  "log": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
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
        $this->expectOutputRegex('/point log\[0\]: no schema for type being_final_open/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsTypeResolvingToMultipleSchemaUrls(): void
    {
        $first = $this->openEntry(['id' => 'being_final_open_1']);
        unset($first['log']);
        $second = $this->openEntry(['id' => 'being_final_open_2', 'schemaUrl' => 'https://example.com/schemas/being-final-open-v2.json']);
        unset($second['log']);
        $this->writeLog(['log' => [$first, $second]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/point log\[1\]: type .being_final_open. already resolves to/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsDuplicateId(): void
    {
        $this->writeLog(['log' => [$this->openEntry(['close' => $this->closeEntry(['id' => 'being_final_open_1'])])]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/close log\[0\]\.close: duplicate id .being_final_open_1./');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsDoubleCloseViaOrphan(): void
    {
        $this->writeLog([
            'log' => [
                $this->openEntry(['close' => $this->closeEntry()]),
                $this->closeEntry(['id' => 'being_final_close_3', 'openId' => 'being_final_open_1']),
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/orphan close log\[1\]: openId .being_final_open_1. names a record in this document: double close/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateWarnsOnInterruptedIntervalWithoutFailing(): void
    {
        $this->writeLog(['log' => [$this->openEntry()]]);

        $this->expectOutputRegex('/interval log\[0\]: unclosed[\s\S]*All contexts validate successfully/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsKindField(): void
    {
        $record = $this->openEntry();
        $record['kind'] = 'open';
        $this->writeLog(['log' => [$record]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/root:/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsNestedOpenId(): void
    {
        $child = $this->openEntry(['id' => 'being_final_open_2']);
        $child['openId'] = 'bogus_1';
        $outer = $this->openEntry(['log' => [$child]]);
        $this->writeLog(['log' => [$outer]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/root:/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsOrphanWithLog(): void
    {
        $orphan = $this->closeEntry(['openId' => 'missing_1']);
        $orphan['log'] = [];
        $this->writeLog(['log' => [$orphan]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/root:/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsOrphanWithClose(): void
    {
        $orphan = $this->closeEntry(['openId' => 'missing_1']);
        $orphan['close'] = $this->closeEntry(['id' => 'being_final_close_3']);
        $this->writeLog(['log' => [$orphan]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/root:/');
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
  "log": [
    {
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
  "log": [
    {
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
  "log": [
    {
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
  "log": [
    {
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
        $this->writeLog(['log' => [$this->openEntry(['log' => [$child], 'close' => $this->closeEntry()])]]);

        $this->expectOutputRegex('/log\[0\]\.log\[0\]\.close \(being_final_close\)[\s\S]*log\[0\]\.close \(being_final_close\)[\s\S]*All contexts validate successfully/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsInvalidNestedChildContext(): void
    {
        $event = $this->openEntry(['id' => 'being_final_open_2', 'context' => ['from' => 'A']]);
        unset($event['log']);
        $this->writeLog(['log' => [$this->openEntry(['log' => [$event]])]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/point log\[0\]\.log\[0\]\.context \(being_final_open\)/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsInvalidNestedCloseContext(): void
    {
        $this->writeLog(['log' => [$this->openEntry(['close' => $this->closeEntry(['context' => ['status' => 1]])])]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/close log\[0\]\.close\.context \(being_final_close\)/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateAcceptsOrphanClose(): void
    {
        $this->writeLog(['log' => [$this->closeEntry(['openId' => 'missing_open_1'])]]);

        $this->expectOutputRegex('/log\[0\] \(being_final_close\)[\s\S]*All contexts validate successfully/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsInvalidOrphanCloseContext(): void
    {
        $this->writeLog(['log' => [$this->closeEntry(['openId' => 'missing_open_1', 'context' => ['status' => 1]])]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/orphan close log\[0\]\.context \(being_final_close\)/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsMissingContextSchema(): void
    {
        $this->writeLog(['log' => [$this->openEntry(['schemaUrl' => 'https://example.com/schemas/unknown.json'])]]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/interval log\[0\]: schema file not found: https:\/\/example\.com\/schemas\/unknown\.json/');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsMissingLog(): void
    {
        $this->writeLog([]);

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/log/');
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
  "log": [
    {
      "id": "being_final_open_1",
      "type": "being_final_open",
      "schemaUrl": "https://be-framework.org/schemas/being-final-open.json",
      "context": {
        "from": "App\\Input\\OrderInput",
        "final": "App\\Final\\OrderConfirmed",
        "input": {},
        "inject": {}
      },
      "log": [
        {
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
        $entry = $overrides + [
            'id' => 'being_final_open_1',
            'type' => 'being_final_open',
            'schemaUrl' => 'https://be-framework.org/schemas/being-final-open.json',
            'context' => ['from' => 'A', 'final' => 'B', 'input' => new stdClass(), 'inject' => new stdClass()],
            'log' => [],
        ];

        // Writer rule: a closed interval with no children omits `log`. If the
        // caller supplied `close` without its own `log`, drop the default
        // empty array so the fixture matches real writer output.
        if (isset($overrides['close']) && ! isset($overrides['log'])) {
            unset($entry['log']);
        }

        return $entry;
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
