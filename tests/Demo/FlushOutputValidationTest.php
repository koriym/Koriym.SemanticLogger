<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Tests\Demo;

use JsonSchema\Validator;
use Koriym\SemanticLogger\FakeContext;
use Koriym\SemanticLogger\SemanticLogger;
use PHPUnit\Framework\TestCase;

use function assert;
use function dirname;
use function file_get_contents;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

final class FlushOutputValidationTest extends TestCase
{
    private object $schema;

    protected function setUp(): void
    {
        $schemaPath = dirname(__DIR__, 2) . '/docs/schemas/semantic-log.json';
        $schemaContent = file_get_contents($schemaPath);
        $this->assertNotFalse($schemaContent, 'Semantic log schema file should exist');

        $schema = json_decode((string) $schemaContent);
        assert($schema !== null);
        /** @var object $schema */
        $this->schema = $schema;
    }

    public function testFlushJsonEncodeOutputValidatesAgainstSchema(): void
    {
        $logger = new SemanticLogger();
        $openId = $logger->open(new FakeContext('open', 1));
        $logger->event(new FakeContext('event', 2));
        $logger->close(new FakeContext('close', 3), $openId);

        $json = json_encode($logger->flush());
        $this->assertNotFalse($json, 'Flush output should encode to JSON');

        $logData = json_decode($json);
        $this->assertNotNull($logData, 'Flush output should decode to JSON');

        $validator = new Validator();
        $validator->validate($logData, $this->schema);

        if (! $validator->isValid()) {
            $errors = $validator->getErrors();
            $errorMessages = [];
            foreach ($errors as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $property = isset($error['property']) && is_string($error['property']) ? $error['property'] : '';
                $message = isset($error['message']) && is_string($error['message']) ? $error['message'] : 'Unknown error';
                $errorMessages[] = "[{$property}] {$message}";
            }

            $this->fail('Flush output validation failed: ' . implode(', ', $errorMessages));
        }

        $this->assertTrue($validator->isValid(), 'Flush output should validate against semantic-log.json');
    }
}
