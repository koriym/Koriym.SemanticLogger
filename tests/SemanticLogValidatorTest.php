<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function file_exists;
use function file_put_contents;
use function is_dir;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;

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

    public function testValidateUsesBundledSchemasForTotalModeDiagnostics(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $logger->event(new class extends AbstractContext {
            public const TYPE = 'Invalid-Type';
            public const SCHEMA_URL = './schemas/not-used.json';

            public string $value = 'kept in diagnostics';
        });
        file_put_contents(
            $this->logFile,
            json_encode($logger->flush()->toArray(), JSON_THROW_ON_ERROR),
        );

        $this->expectOutputRegex('/semantic_logger_invalid_context.*semantic_logger_error.*All contexts validate successfully/s');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateRejectsUnsupportedCoreDiagnosticKind(): void
    {
        file_put_contents(
            $this->logFile,
            json_encode([
                '$schema' => CoreSchema::LOG_URL,
                'open' => [],
                'events' => [
                    [
                        'id' => 'semantic_logger_invalid_context_1',
                        'type' => CoreSchema::INVALID_CONTEXT_TYPE,
                        'schemaUrl' => CoreSchema::INVALID_CONTEXT_URL,
                        'context' => [
                            'operation' => 'event',
                            'errors' => [
                                [
                                    'kind' => 'unsupported_kind',
                                    'message' => 'invalid protocol value',
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/semantic_logger_invalid_context.*enumeration/s');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }

    public function testValidateAcceptsEmptyDiscardedContextInCoreDiagnostic(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $logger->close(new class extends AbstractContext {
            public const TYPE = 'empty_context';
            public const SCHEMA_URL = './schemas/not-used.json';
        }, 'missing_1');
        file_put_contents(
            $this->logFile,
            json_encode($logger->flush()->toArray(), JSON_THROW_ON_ERROR),
        );

        $this->expectOutputRegex('/semantic_logger_error.*All contexts validate successfully/s');
        self::assertNotNull($this->validator);
        $this->validator->validate($this->logFile, $this->schemaDirectory);
    }
}
