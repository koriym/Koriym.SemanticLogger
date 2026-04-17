<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function array_key_exists;
use function count;
use function implode;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function parse_url;
use function sprintf;
use function strlen;
use function substr;

final class TreeNode
{
    /** @var TreeNode[] */
    public array $children = [];

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $context,
        public readonly float $executionTime = 0.0,
        public readonly TreeNode|null $parent = null,
    ) {
    }

    public function addChild(TreeNode $child): void
    {
        $this->children[] = $child;
    }

    public function getDisplayName(): string
    {
        return $this->type;
    }

    public function getDisplayLine(RenderConfig|null $config = null): string
    {
        $timeDisplay = $this->formatExecutionTime();
        $contextInfo = $this->extractContextInfo($config);

        if ($contextInfo !== '') {
            return sprintf('%s::%s [%s]', $this->type, $contextInfo, $timeDisplay);
        }

        return sprintf('%s [%s]', $this->type, $timeDisplay);
    }

    private function formatExecutionTime(): string
    {
        if ($this->executionTime < 0.001) {
            return sprintf('%.1fμs', $this->executionTime * 1_000_000.0);
        }

        if ($this->executionTime < 1.0) {
            return sprintf('%.1fms', $this->executionTime * 1000.0);
        }

        return sprintf('%.1fs', $this->executionTime);
    }

    public function extractContextInfo(RenderConfig|null $config = null): string
    {
        return match ($this->type) {
            'http_request' => $this->extractHttpRequestInfo($config),
            'http_response' => $this->extractHttpResponseInfo(),
            'database_connection' => $this->extractDatabaseConnectionInfo(),
            'database_query', 'complex_query' => $this->extractDatabaseQueryInfo($config),
            'external_api_request' => $this->extractExternalApiInfo(),
            'cache_operation' => $this->extractCacheOperationInfo(),
            'file_processing' => $this->extractFileProcessingInfo(),
            'authentication_request', 'authentication' => $this->extractAuthenticationInfo(),
            'business_logic' => $this->extractBusinessLogicInfo(),
            'error' => $this->extractErrorInfo(),
            'performance_metrics' => $this->extractPerformanceMetricsInfo(),
            default => $this->extractDefaultInfo(),
        };
    }

    /** @codeCoverageIgnore */
    private function extractHttpRequestInfo(RenderConfig|null $config): string
    {
        $method = $this->contextString('method');
        $uri = $this->contextString('uri');

        /** @var mixed $headers */
        $headers = $this->context['headers'] ?? [];
        if (is_array($headers) && $headers !== []) {
            $headerInfo = $this->formatMultiLineData($headers, $config);

            return sprintf('%s %s (headers: %s)', $method, $uri, $headerInfo);
        }

        return sprintf('%s %s', $method, $uri);
    }

    /** @codeCoverageIgnore */
    private function extractHttpResponseInfo(): string
    {
        return sprintf('Status %s', $this->contextString('statusCode'));
    }

    /** @codeCoverageIgnore */
    private function extractDatabaseConnectionInfo(): string
    {
        return sprintf('%s/%s', $this->contextString('host'), $this->contextString('database'));
    }

    /** @codeCoverageIgnore */
    private function extractDatabaseQueryInfo(RenderConfig|null $config): string
    {
        $queryType = $this->contextString('queryType');
        $table = $this->contextString('table');

        /** @var mixed $parameters */
        $parameters = $this->context['parameters'] ?? [];
        if (is_array($parameters) && $parameters !== []) {
            $paramInfo = $this->formatMultiLineData($parameters, $config);

            return sprintf('%s %s (params: %s)', $queryType, $table, $paramInfo);
        }

        return sprintf('%s %s', $queryType, $table);
    }

    /** @codeCoverageIgnore */
    private function extractExternalApiInfo(): string
    {
        return sprintf(
            '%s %s',
            $this->contextString('service'),
            $this->shortenUrl($this->contextString('endpoint')),
        );
    }

    /** @codeCoverageIgnore */
    private function extractCacheOperationInfo(): string
    {
        $hit = ($this->context['hit'] ?? false) === true ? 'HIT' : 'MISS';

        return sprintf('%s %s (%s)', $this->contextString('operation'), $this->contextString('key'), $hit);
    }

    /** @codeCoverageIgnore */
    private function extractFileProcessingInfo(): string
    {
        return sprintf('%s %s', $this->contextString('operation'), $this->contextString('filename'));
    }

    /** @codeCoverageIgnore */
    private function extractAuthenticationInfo(): string
    {
        $method = $this->contextString('method');
        /** @var mixed $token */
        $token = $this->context['token'] ?? null;
        $status = $token !== null && $token !== '' && $token !== false ? 'SUCCESS' : 'FAILED';

        return sprintf('%s (%s)', $method, $status);
    }

    /** @codeCoverageIgnore */
    private function extractBusinessLogicInfo(): string
    {
        $operation = $this->contextString('operation');
        $success = ($this->context['success'] ?? false) === true ? 'SUCCESS' : 'FAILED';

        return sprintf('%s (%s)', $operation, $success);
    }

    /** @codeCoverageIgnore */
    private function extractErrorInfo(): string
    {
        return sprintf(
            '%s: %s',
            $this->contextString('errorType'),
            $this->truncateMessage($this->contextString('message')),
        );
    }

    /** @codeCoverageIgnore */
    private function extractPerformanceMetricsInfo(): string
    {
        $queries = $this->contextInt('databaseQueries');
        $memory = $this->contextFloat('memoryUsed');

        return sprintf('%d queries, %s memory', $queries, $this->formatBytes($memory));
    }

    /** @codeCoverageIgnore */
    private function extractDefaultInfo(): string
    {
        if (array_key_exists('operation', $this->context)) {
            return $this->contextString('operation');
        }

        if (array_key_exists('method', $this->context)) {
            return $this->contextString('method');
        }

        if (array_key_exists('name', $this->context)) {
            return $this->contextString('name');
        }

        return '';
    }

    private function contextString(string $key): string
    {
        /** @var mixed $value */
        $value = $this->context[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function contextInt(string $key): int
    {
        /** @var mixed $value */
        $value = $this->context[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function contextFloat(string $key): float
    {
        /** @var mixed $value */
        $value = $this->context[$key] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** @codeCoverageIgnore */
    private function shortenUrl(string $url): string
    {
        if (strlen($url) <= 40) {
            return $url;
        }

        // Extract just the path part for display
        $parsed = parse_url($url);
        if (isset($parsed['host'])) {
            $host = $parsed['host'];
            $path = $parsed['path'] ?? '';

            return $host . $path;
        }

        return substr($url, 0, 37) . '...';
    }

    /** @codeCoverageIgnore */
    private function truncateMessage(string $message): string
    {
        if (strlen($message) <= 60) {
            return $message;
        }

        return substr($message, 0, 57) . '...';
    }

    /**
     * Format multi-line data with line limits
     */
    private function formatMultiLineData(mixed $data, RenderConfig|null $config = null): string
    {
        $maxLines = $config->maxLines ?? 5;

        if ($maxLines <= 0) {
            // No limit
            return $this->convertDataToString($data);
        }

        if (! is_array($data)) {
            return is_scalar($data) ? (string) $data : '';
        }

        $items = [];
        $count = 0;

        /** @var mixed $value */
        foreach ($data as $key => $value) {
            if ($count >= $maxLines) {
                $remaining = count($data) - $maxLines;
                $items[] = "... ({$remaining} more)";
                break;
            }

            if (is_scalar($value)) {
                $items[] = is_string($key) ? "{$key}: {$value}" : (string) $value;

                $count++;

                continue;
            }

            $items[] = is_string($key) ? "{$key}: [complex]" : '[complex]';

            $count++;
        }

        return implode(', ', $items);
    }

    /**
     * Convert data to string representation
     */

    /** @codeCoverageIgnore */
    private function convertDataToString(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }

        if (is_array($data)) {
            $items = [];
            /** @var mixed $value */
            foreach ($data as $key => $value) {
                if (is_scalar($value)) {
                    $items[] = is_string($key) ? "{$key}: {$value}" : (string) $value;

                    continue;
                }

                $items[] = is_string($key) ? "{$key}: [complex]" : '[complex]';
            }

            return implode(', ', $items);
        }

        return is_scalar($data) ? (string) $data : '';
    }

    /** @codeCoverageIgnore */
    private function formatBytes(int|float $bytes): string
    {
        $bytes = (float) $bytes;

        if ($bytes < 1024.0) {
            return sprintf('%.0fB', $bytes);
        }

        if ($bytes < 1024.0 * 1024.0) {
            return sprintf('%.1fKB', $bytes / 1024.0);
        }

        return sprintf('%.1fMB', $bytes / (1024.0 * 1024.0));
    }
}
