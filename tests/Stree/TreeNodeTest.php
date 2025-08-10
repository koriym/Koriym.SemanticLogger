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

    public function testHttpRequestWithHeaders(): void
    {
        $context = [
            'method' => 'POST',
            'uri' => '/api/orders',
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer token123',
            ],
        ];
        $config = new RenderConfig(2, [], 0.0, false, 5);
        $node = new TreeNode('req_1', 'http_request', $context);

        $displayLine = $node->getDisplayLine($config);

        $this->assertStringContainsString('POST /api/orders', $displayLine);
        $this->assertStringContainsString('headers:', $displayLine);
        $this->assertStringContainsString('Content-Type: application/json', $displayLine);
    }

    public function testComplexQueryWithParameters(): void
    {
        $context = [
            'queryType' => 'SELECT',
            'table' => 'users',
            'parameters' => [
                'id' => 123,
                'status' => 'active',
            ],
        ];
        $config = new RenderConfig(2, [], 0.0, false, 5);
        $node = new TreeNode('query_1', 'complex_query', $context);

        $displayLine = $node->getDisplayLine($config);

        $this->assertStringContainsString('SELECT users', $displayLine);
        $this->assertStringContainsString('params:', $displayLine);
        $this->assertStringContainsString('id: 123', $displayLine);
    }

    public function testFileProcessingContextInfo(): void
    {
        $context = [
            'operation' => 'pdf_generation',
            'filename' => 'invoice_123.pdf',
        ];
        $node = new TreeNode('file_1', 'file_processing', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('file_processing::pdf_generation invoice_123.pdf', $displayLine);
    }

    public function testBusinessLogicContextInfo(): void
    {
        $context = [
            'operation' => 'order_validation',
            'success' => true,
        ];
        $node = new TreeNode('biz_1', 'business_logic', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('business_logic::order_validation (SUCCESS)', $displayLine);
    }

    public function testBusinessLogicFailedContextInfo(): void
    {
        $context = [
            'operation' => 'payment_processing',
            'success' => false,
        ];
        $node = new TreeNode('biz_1', 'business_logic', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('business_logic::payment_processing (FAILED)', $displayLine);
    }

    public function testCacheMissContextInfo(): void
    {
        $context = [
            'operation' => 'get',
            'key' => 'user_profile_456',
            'hit' => false,
        ];
        $node = new TreeNode('cache_1', 'cache_operation', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('cache_operation::get user_profile_456 (MISS)', $displayLine);
    }

    public function testGenericContextWithMethod(): void
    {
        $context = ['method' => 'GET'];
        $node = new TreeNode('generic_1', 'custom_type', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('custom_type::GET', $displayLine);
    }

    public function testGenericContextWithName(): void
    {
        $context = ['name' => 'service_call'];
        $node = new TreeNode('generic_1', 'custom_type', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('custom_type::service_call', $displayLine);
    }

    public function testShortenLongUrl(): void
    {
        $context = [
            'service' => 'PaymentGateway',
            'endpoint' => 'https://api.very-long-domain-name.example.com/v3/payments/authorize/with/very/long/path',
        ];
        $node = new TreeNode('api_1', 'external_api_request', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('PaymentGateway', $displayLine);
        $this->assertStringContainsString('api.very-long-domain-name.example.com', $displayLine);
    }

    public function testShortenUrlFallback(): void
    {
        $context = [
            'service' => 'Service',
            'endpoint' => 'not-a-valid-url-but-very-long-string-that-should-be-truncated-because-it-exceeds-40-chars',
        ];
        $node = new TreeNode('api_1', 'external_api_request', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('Service', $displayLine);
        $this->assertStringContainsString('...', $displayLine);
    }

    public function testTruncateLongErrorMessage(): void
    {
        $context = [
            'errorType' => 'ValidationError',
            'message' => 'This is a very long error message that should be truncated because it exceeds the maximum length limit',
        ];
        $node = new TreeNode('error_1', 'error', $context);

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('ValidationError:', $displayLine);
        $this->assertStringContainsString('...', $displayLine);
        $this->assertLessThan(120, strlen($displayLine)); // Should be truncated
    }

    public function testFormatBytesVariousUnits(): void
    {
        // Test bytes
        $context = ['databaseQueries' => 3, 'memoryUsed' => 512];
        $node = new TreeNode('perf_1', 'performance_metrics', $context);
        $displayLine = $node->getDisplayLine();
        $this->assertStringContainsString('512B', $displayLine);

        // Test kilobytes
        $context = ['databaseQueries' => 3, 'memoryUsed' => 2048];
        $node = new TreeNode('perf_2', 'performance_metrics', $context);
        $displayLine = $node->getDisplayLine();
        $this->assertStringContainsString('2.0KB', $displayLine);

        // Test megabytes
        $context = ['databaseQueries' => 5, 'memoryUsed' => 2097152]; // 2MB
        $node = new TreeNode('perf_3', 'performance_metrics', $context);
        $displayLine = $node->getDisplayLine();
        $this->assertStringContainsString('2.0MB', $displayLine);
    }

    public function testMultiLineDataWithLimits(): void
    {
        $context = [
            'method' => 'POST',
            'uri' => '/api/test',
            'headers' => [
                'Header1' => 'Value1',
                'Header2' => 'Value2',
                'Header3' => 'Value3',
                'Header4' => 'Value4',
                'Header5' => 'Value5',
                'Header6' => 'Value6', // This should trigger truncation
            ],
        ];
        $config = new RenderConfig(2, [], 0.0, false, 3); // Limit to 3 lines
        $node = new TreeNode('req_1', 'http_request', $context);

        $displayLine = $node->getDisplayLine($config);

        $this->assertStringContainsString('POST /api/test', $displayLine);
        $this->assertStringContainsString('... (3 more)', $displayLine);
    }

    public function testMultiLineDataWithoutLimits(): void
    {
        $context = [
            'method' => 'POST',
            'uri' => '/api/test',
            'headers' => [
                'Header1' => 'Value1',
                'Header2' => 'Value2',
                'Header3' => 'Value3',
            ],
        ];
        $config = new RenderConfig(2, [], 0.0, false, 0); // No limit
        $node = new TreeNode('req_1', 'http_request', $context);

        $displayLine = $node->getDisplayLine($config);

        $this->assertStringContainsString('POST /api/test', $displayLine);
        $this->assertStringContainsString('Header1: Value1', $displayLine);
        $this->assertStringContainsString('Header2: Value2', $displayLine);
        $this->assertStringContainsString('Header3: Value3', $displayLine);
        $this->assertStringNotContainsString('more)', $displayLine);
    }

    public function testComplexDataHandling(): void
    {
        $context = [
            'queryType' => 'SELECT',
            'table' => 'users',
            'parameters' => [
                'simple' => 'value',
                'complex' => ['nested' => 'array'],
                'another' => 'simple_value',
            ],
        ];
        $config = new RenderConfig(2, [], 0.0, false, 5);
        $node = new TreeNode('query_1', 'complex_query', $context);

        $displayLine = $node->getDisplayLine($config);

        $this->assertStringContainsString('SELECT users', $displayLine);
        $this->assertStringContainsString('simple: value', $displayLine);
        $this->assertStringContainsString('complex: [complex]', $displayLine);
        $this->assertStringContainsString('another: simple_value', $displayLine);
    }
}
