<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Demo;

use JsonSchema\Validator;
use Koriym\SemanticLogger\AuthenticationErrorContext;
use PHPUnit\Framework\TestCase;

use function count;
use function file_get_contents;
use function json_decode;
use function json_encode;

final class AuthenticationErrorContextTest extends TestCase
{
    private Validator $validator;
    private object $schema;

    protected function setUp(): void
    {
        $this->validator = new Validator();

        $schemaPath = __DIR__ . '/../../demo/schemas/authentication_error.json';
        $schemaContent = file_get_contents($schemaPath);
        $this->assertNotFalse($schemaContent, 'Schema file should exist and be readable');

        $this->schema = json_decode($schemaContent);
        $this->assertNotNull($this->schema, 'Schema should be valid JSON');
    }

    public function testValidAuthenticationErrorContext(): void
    {
        $errorData = [
            'errorType' => 'InvalidCredentials',
            'message' => 'Username or password is incorrect',
            'code' => 401,
            'attemptedMethod' => 'BasicAuth',
            'timestamp' => '2025-08-07T12:00:00Z',
            'userId' => null,
            'ipAddress' => '192.168.1.100',
            'userAgent' => 'Mozilla/5.0 (compatible; Test)',
            'additionalInfo' => [
                'attempts' => 3,
                'lockoutDuration' => 300,
            ],
        ];

        $context = new AuthenticationErrorContext($errorData);
        $contextArray = (array) $context;
        $contextObject = json_decode(json_encode($contextArray));

        // Validate against schema
        $this->validator->validate($contextObject, $this->schema);
        $this->assertTrue($this->validator->isValid(), 'Context should be valid: ' . json_encode($this->validator->getErrors()));
    }

    public function testMinimalValidAuthenticationErrorContext(): void
    {
        $errorData = [
            'errorType' => 'ExpiredToken',
            'message' => 'Authentication token has expired',
            'code' => 401,
            'attemptedMethod' => 'JWT',
            'timestamp' => '2025-08-07T12:00:00Z',
        ];

        $context = new AuthenticationErrorContext($errorData);
        $contextArray = (array) $context;
        $contextObject = json_decode(json_encode($contextArray));

        // Validate against schema
        $this->validator->validate($contextObject, $this->schema);
        $this->assertTrue($this->validator->isValid(), 'Minimal context should be valid: ' . json_encode($this->validator->getErrors()));
    }

    public function testInvalidErrorType(): void
    {
        $errorData = [
            'errorType' => 'InvalidErrorType',
            'message' => 'Test message',
            'code' => 401,
            'attemptedMethod' => 'JWT',
            'timestamp' => '2025-08-07T12:00:00Z',
        ];

        $context = new AuthenticationErrorContext($errorData);
        $contextArray = (array) $context;
        $contextObject = json_decode(json_encode($contextArray));

        // Validate against schema
        $this->validator->validate($contextObject, $this->schema);
        $this->assertFalse($this->validator->isValid(), 'Context with invalid errorType should fail validation');

        $errors = $this->validator->getErrors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('errorType', json_encode($errors[0]));
    }

    public function testInvalidHttpStatusCode(): void
    {
        $errorData = [
            'errorType' => 'InvalidCredentials',
            'message' => 'Test message',
            'code' => 200, // Invalid for authentication error
            'attemptedMethod' => 'JWT',
            'timestamp' => '2025-08-07T12:00:00Z',
        ];

        $context = new AuthenticationErrorContext($errorData);
        $contextArray = (array) $context;
        $contextObject = json_decode(json_encode($contextArray));

        // Validate against schema
        $this->validator->validate($contextObject, $this->schema);
        $this->assertFalse($this->validator->isValid(), 'Context with non-4xx status code should fail validation');

        $errors = $this->validator->getErrors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('code', json_encode($errors[0]));
    }

    public function testMissingRequiredFields(): void
    {
        $errorData = [
            'errorType' => 'InvalidCredentials',
            'message' => 'Test message',
            // Missing required fields: code, attemptedMethod, timestamp
        ];

        $context = new AuthenticationErrorContext($errorData);
        $contextArray = (array) $context;
        $contextObject = json_decode(json_encode($contextArray));

        // Validate against schema
        $this->validator->validate($contextObject, $this->schema);
        $this->assertFalse($this->validator->isValid(), 'Context with missing required fields should fail validation');

        $errors = $this->validator->getErrors();
        $this->assertGreaterThanOrEqual(3, count($errors), 'Should have errors for missing required fields');
    }

    public function testContextConstants(): void
    {
        $this->assertEquals('authentication_error', AuthenticationErrorContext::TYPE);
        $this->assertEquals('../schemas/authentication_error.json', AuthenticationErrorContext::SCHEMA_URL);
    }

    public function testSchemaIdAndTitle(): void
    {
        $this->assertEquals('https://koriym.github.io/Koriym.SemanticLogger/schemas/authentication_error.json', $this->schema->{'$id'});
        $this->assertEquals('Authentication Error Context', $this->schema->title);
        $this->assertStringContainsString('authentication failure', $this->schema->description);
    }
}
