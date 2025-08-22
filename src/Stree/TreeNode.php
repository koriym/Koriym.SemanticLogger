<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function array_key_exists;
use function count;
use function implode;
use function is_array;
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
        $method = $this->context['method'] ?? '';
        $uri = $this->context['uri'] ?? '';

        $headers = $this->context['headers'] ?? [];
        if (! empty($headers) && is_array($headers)) {
            $headerInfo = $this->formatMultiLineData($headers, $config);

            return sprintf('%s %s (headers: %s)', $method, $uri, $headerInfo);
        }

        return sprintf('%s %s', $method, $uri);
    }

    /** @codeCoverageIgnore */
    private function extractHttpResponseInfo(): string
    {
        $status = $this->context['statusCode'] ?? '';

        return sprintf('Status %s', $status);
    }

    /** @codeCoverageIgnore */
    private function extractDatabaseConnectionInfo(): string
    {
        $host = $this->context['host'] ?? '';
        $db = $this->context['database'] ?? '';

        return sprintf('%s/%s', $host, $db);
    }

    /** @codeCoverageIgnore */
    private function extractDatabaseQueryInfo(RenderConfig|null $config): string
    {
        $queryType = (string) ($this->context['queryType'] ?? '');
        $table = (string) ($this->context['table'] ?? '');

        $parameters = $this->context['parameters'] ?? [];
        if (! empty($parameters) && is_array($parameters)) {
            $paramInfo = $this->formatMultiLineData($parameters, $config);

            return sprintf('%s %s (params: %s)', $queryType, $table, $paramInfo);
        }

        return sprintf('%s %s', $queryType, $table);
    }

    /** @codeCoverageIgnore */
    private function extractExternalApiInfo(): string
    {
        $service = (string) ($this->context['service'] ?? '');
        $endpoint = (string) ($this->context['endpoint'] ?? '');

        return sprintf('%s %s', $service, $this->shortenUrl($endpoint));
    }

    /** @codeCoverageIgnore */
    private function extractCacheOperationInfo(): string
    {
        $operation = (string) ($this->context['operation'] ?? '');
        $key = (string) ($this->context['key'] ?? '');
        $hit = $this->context['hit'] ?? false ? 'HIT' : 'MISS';

        return sprintf('%s %s (%s)', $operation, $key, $hit);
    }

    /** @codeCoverageIgnore */
    private function extractFileProcessingInfo(): string
    {
        $operation = (string) ($this->context['operation'] ?? '');
        $filename = (string) ($this->context['filename'] ?? '');

        return sprintf('%s %s', $operation, $filename);
    }

    /** @codeCoverageIgnore */
    private function extractAuthenticationInfo(): string
    {
        $method = (string) ($this->context['method'] ?? '');
        $token = $this->context['token'] ?? null;
        $status = $token ? 'SUCCESS' : 'FAILED';

        return sprintf('%s (%s)', $method, $status);
    }

    /** @codeCoverageIgnore */
    private function extractBusinessLogicInfo(): string
    {
        $operation = (string) ($this->context['operation'] ?? '');
        $success = $this->context['success'] ?? false ? 'SUCCESS' : 'FAILED';

        return sprintf('%s (%s)', $operation, $success);
    }

    /** @codeCoverageIgnore */
    private function extractErrorInfo(): string
    {
        $errorType = (string) ($this->context['errorType'] ?? '');
        $message = (string) ($this->context['message'] ?? '');

        return sprintf('%s: %s', $errorType, $this->truncateMessage($message));
    }

    /** @codeCoverageIgnore */
    private function extractPerformanceMetricsInfo(): string
    {
        $queries = (int) ($this->context['databaseQueries'] ?? 0);
        $memory = (float) ($this->context['memoryUsed'] ?? 0);

        return sprintf('%d queries, %s memory', $queries, $this->formatBytes($memory));
    }

    /** @codeCoverageIgnore */
    private function extractDefaultInfo(): string
    {
        if (array_key_exists('operation', $this->context)) {
            return (string) $this->context['operation'];
        }

        if (array_key_exists('method', $this->context)) {
            return (string) $this->context['method'];
        }

        if (array_key_exists('name', $this->context)) {
            return (string) $this->context['name'];
        }

        return '';
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
            return (string) $data;
        }

        $items = [];
        $count = 0;

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
            foreach ($data as $key => $value) {
                if (is_scalar($value)) {
                    $items[] = is_string($key) ? "{$key}: {$value}" : (string) $value;

                    continue;
                }

                $items[] = is_string($key) ? "{$key}: [complex]" : '[complex]';
            }

            return implode(', ', $items);
        }

        return (string) $data;
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
