<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Complex web request simulation contexts for realistic testing
 */

// HTTP Request Context
final class HttpRequestContext extends AbstractContext
{
    public const TYPE = 'http_request';
    public const SCHEMA_URL = 'https://example.com/schemas/http_request.json';

    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $headers,
        public readonly ?array $body = null,
        public readonly string $userAgent = '',
        public readonly string $clientIp = '',
    ) {
    }
}

// Authentication Context
final class AuthenticationContext extends AbstractContext
{
    public const TYPE = 'authentication';
    public const SCHEMA_URL = 'https://example.com/schemas/authentication.json';

    public function __construct(
        public readonly string $method,
        public readonly ?string $userId = null,
        public readonly array $claims = [],
        public readonly bool $success = false,
        public readonly float $duration = 0.0,
    ) {
    }
}

// Database Connection Context
final class DatabaseConnectionContext extends AbstractContext
{
    public const TYPE = 'database_connection';
    public const SCHEMA_URL = 'https://example.com/schemas/database_connection.json';

    public function __construct(
        public readonly string $driver,
        public readonly string $host,
        public readonly string $database,
        public readonly float $connectionTime,
        public readonly bool $pooled = false,
    ) {
    }
}

// Complex Database Query Context
final class ComplexQueryContext extends AbstractContext
{
    public const TYPE = 'complex_query';
    public const SCHEMA_URL = 'https://example.com/schemas/complex_query.json';

    public function __construct(
        public readonly string $queryType,
        public readonly string $tables,
        public readonly array $conditions,
        public readonly int $paramCount,
        public readonly float $executionTime,
        public readonly int $rowsAffected,
        public readonly bool $cached = false,
        public readonly ?string $cacheKey = null,
    ) {
    }
}

// Cache Operation Context
final class CacheOperationContext extends AbstractContext
{
    public const TYPE = 'cache_operation';
    public const SCHEMA_URL = 'https://example.com/schemas/cache_operation.json';

    public function __construct(
        public readonly string $operation,
        public readonly string $key,
        public readonly bool $hit,
        public readonly float $latency,
        public readonly ?int $ttl = null,
        public readonly int $size = 0,
    ) {
    }
}

// External API Call Context
final class ExternalApiContext extends AbstractContext
{
    public const TYPE = 'external_api';
    public const SCHEMA_URL = 'https://example.com/schemas/external_api.json';

    public function __construct(
        public readonly string $service,
        public readonly string $endpoint,
        public readonly string $method,
        public readonly int $statusCode,
        public readonly float $responseTime,
        public readonly int $requestSize,
        public readonly int $responseSize,
        public readonly int $retryCount = 0,
    ) {
    }
}

// Business Logic Context
final class BusinessLogicContext extends AbstractContext
{
    public const TYPE = 'business_logic';
    public const SCHEMA_URL = 'https://example.com/schemas/business_logic.json';

    public function __construct(
        public readonly string $operation,
        public readonly array $inputData,
        public readonly array $outputData,
        public readonly array $validationRules,
        public readonly bool $success,
        public readonly ?string $errorMessage = null,
    ) {
    }
}

// File Processing Context
final class FileProcessingContext extends AbstractContext
{
    public const TYPE = 'file_processing';
    public const SCHEMA_URL = 'https://example.com/schemas/file_processing.json';

    public function __construct(
        public readonly string $operation,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $fileSize,
        public readonly float $processingTime,
        public readonly bool $success,
        public readonly ?string $outputPath = null,
    ) {
    }
}

// Error Context
final class ErrorContext extends AbstractContext
{
    public const TYPE = 'error';
    public const SCHEMA_URL = 'https://example.com/schemas/error.json';

    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly int $code,
        public readonly string $file,
        public readonly int $line,
        public readonly array $trace,
        public readonly array $context = [],
    ) {
    }
}

// Performance Metrics Context
final class PerformanceMetricsContext extends AbstractContext
{
    public const TYPE = 'performance_metrics';
    public const SCHEMA_URL = 'https://example.com/schemas/performance_metrics.json';

    public function __construct(
        public readonly float $executionTime,
        public readonly int $memoryUsage,
        public readonly int $peakMemory,
        public readonly int $queryCount,
        public readonly float $queryTime,
        public readonly int $cacheHits,
        public readonly int $cacheMisses,
        public readonly array $functionCalls = [],
    ) {
    }
}

// Response Context
final class HttpResponseContext extends AbstractContext
{
    public const TYPE = 'http_response';
    public const SCHEMA_URL = 'https://example.com/schemas/http_response.json';

    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly int $contentLength,
        public readonly string $contentType,
        public readonly float $responseTime,
        public readonly bool $cached = false,
    ) {
    }
}