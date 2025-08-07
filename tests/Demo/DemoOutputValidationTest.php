<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Tests\Demo;

use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

use function assert;
use function dirname;
use function file_get_contents;
use function implode;
use function json_decode;

final class DemoOutputValidationTest extends TestCase
{
    private Validator $validator;
    private object $schema;

    protected function setUp(): void
    {
        $this->validator = new Validator();

        // Load semantic-log.json schema
        $schemaPath = dirname(__DIR__, 2) . '/docs/schemas/semantic-log.json';
        $schemaContent = file_get_contents($schemaPath);
        $this->assertNotFalse($schemaContent, 'Semantic log schema file should exist');

        $schema = json_decode((string) $schemaContent);
        assert($schema !== null);
        /** @var object $schema */
        $this->schema = $schema;
    }

    public function testDemoSemanticLogValidatesAgainstSchema(): void
    {
        // Load the generated demo.json (created by running demo/run.php)
        $demoPath = dirname(__DIR__, 2) . '/demo';
        $jsonPath = $demoPath . '/demo.json';
        $this->assertFileExists($jsonPath, 'demo.json should exist (run "cd demo && php run.php" to generate)');

        $jsonContent = file_get_contents($jsonPath);
        $this->assertNotFalse($jsonContent, 'JSON file should be readable');

        $logData = json_decode($jsonContent);
        $this->assertNotNull($logData, 'semantic-log.json should contain valid JSON');

        // Validate against semantic-log.json schema
        $this->validator->validate($logData, $this->schema);

        if (! $this->validator->isValid()) {
            $errors = $this->validator->getErrors();
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = "[{$error['property']}] {$error['message']}";
            }

            $this->fail('Demo output validation failed: ' . implode(', ', $errorMessages));
        }

        $this->assertTrue($this->validator->isValid(), 'Demo demo.json should validate against schema');
    }
}
