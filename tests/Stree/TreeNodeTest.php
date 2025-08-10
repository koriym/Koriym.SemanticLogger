<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use PHPUnit\Framework\TestCase;

final class TreeNodeTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $node = new TreeNode('test_1', 'test_type', ['key' => 'value'], 0.005);

        $this->assertSame('test_1', $node->id);
        $this->assertSame('test_type', $node->type);
        $this->assertSame(['key' => 'value'], $node->context);
        $this->assertSame(0.005, $node->executionTime);
        $this->assertEmpty($node->children);
    }

    public function testAddChild(): void
    {
        $parent = new TreeNode('parent_1', 'parent', []);
        $child = new TreeNode('child_1', 'child', []);

        $parent->addChild($child);

        $this->assertCount(1, $parent->children);
        $this->assertSame($child, $parent->children[0]);
    }

    public function testGetDisplayName(): void
    {
        $node = new TreeNode('test_1', 'test_type', []);

        $this->assertSame('test_type', $node->getDisplayName());
    }

    public function testFormatExecutionTimeMicroseconds(): void
    {
        $node = new TreeNode('test_1', 'test_type', [], 0.0005); // 0.5ms

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('[500.0μs]', $displayLine);
    }

    public function testFormatExecutionTimeMilliseconds(): void
    {
        $node = new TreeNode('test_1', 'test_type', [], 0.025); // 25ms

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('[25.0ms]', $displayLine);
    }

    public function testFormatExecutionTimeSeconds(): void
    {
        $node = new TreeNode('test_1', 'test_type', [], 1.5); // 1.5s

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('[1.5s]', $displayLine);
    }

    public function testHttpRequestContextInfo(): void
    {
        $context = [
            'method' => 'POST',
            'uri' => '/api/users',
        ];
        $node = new TreeNode('req_1', 'http_request', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('http_request::POST /api/users', $displayLine);
    }

    public function testHttpResponseContextInfo(): void
    {
        $context = ['statusCode' => 200];
        $node = new TreeNode('res_1', 'http_response', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('http_response::Status 200', $displayLine);
    }

    public function testDatabaseConnectionContextInfo(): void
    {
        $context = [
            'host' => 'localhost',
            'database' => 'test_db',
        ];
        $node = new TreeNode('db_1', 'database_connection', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('database_connection::localhost/test_db', $displayLine);
    }

    public function testComplexQueryContextInfo(): void
    {
        $context = [
            'queryType' => 'SELECT',
            'table' => 'users',
        ];
        $node = new TreeNode('query_1', 'complex_query', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('complex_query::SELECT users', $displayLine);
    }

    public function testExternalApiRequestContextInfo(): void
    {
        $context = [
            'service' => 'PaymentGateway',
            'endpoint' => 'https://api.payments.example.com/v2/authorize',
        ];
        $node = new TreeNode('api_1', 'external_api_request', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('external_api_request::PaymentGateway api.payments.example.com/v2/authorize', $displayLine);
    }

    public function testCacheOperationContextInfo(): void
    {
        $context = [
            'operation' => 'get',
            'key' => 'user_session_123',
            'hit' => true,
        ];
        $node = new TreeNode('cache_1', 'cache_operation', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('cache_operation::get user_session_123 (HIT)', $displayLine);
    }

    public function testAuthenticationContextInfo(): void
    {
        $context = [
            'method' => 'JWT',
            'token' => 'valid_token_123',
        ];
        $node = new TreeNode('auth_1', 'authentication_request', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('authentication_request::JWT (SUCCESS)', $displayLine);
    }

    public function testAuthenticationFailedContextInfo(): void
    {
        $context = [
            'method' => 'JWT',
            'token' => null,
        ];
        $node = new TreeNode('auth_1', 'authentication_request', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('authentication_request::JWT (FAILED)', $displayLine);
    }

    public function testErrorContextInfo(): void
    {
        $context = [
            'errorType' => 'ValidationError',
            'message' => 'Invalid email address format',
        ];
        $node = new TreeNode('error_1', 'error', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('error::ValidationError: Invalid email address format', $displayLine);
    }

    public function testPerformanceMetricsContextInfo(): void
    {
        $context = [
            'databaseQueries' => 5,
            'memoryUsed' => 1048576, // 1MB
        ];
        $node = new TreeNode('perf_1', 'performance_metrics', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('performance_metrics::5 queries, 1.0MB memory', $displayLine);
    }

    public function testGenericContextInfoWithOperation(): void
    {
        $context = ['operation' => 'file_upload'];
        $node = new TreeNode('generic_1', 'unknown_type', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('unknown_type::file_upload', $displayLine);
    }

    public function testGenericContextInfoWithoutRecognizedFields(): void
    {
        $context = ['custom_field' => 'value'];
        $node = new TreeNode('generic_1', 'unknown_type', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('unknown_type [', $displayLine);
        $this->assertStringNotContainsString('::', $displayLine);
    }
}
