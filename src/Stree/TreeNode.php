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
            return sprintf('%.1fμs', $this->executionTime * 1_000_000);
        }

        if ($this->executionTime < 1.0) {
            return sprintf('%.1fms', $this->executionTime * 1000);
        }

        return sprintf('%.1fs', $this->executionTime);
    }

    public function extractContextInfo(RenderConfig|null $config = null): string
    {
        // Try to extract meaningful info from context based on type
        switch ($this->type) {
            case 'http_request':
                $method = $this->context['method'] ?? '';
                $uri = $this->context['uri'] ?? '';

                // Add headers info if present and line limit allows
                $headers = $this->context['headers'] ?? [];
                if (! empty($headers) && is_array($headers)) {
                    $headerInfo = $this->formatMultiLineData($headers, $config);

                    return sprintf('%s %s (headers: %s)', $method, $uri, $headerInfo);
                }

                return sprintf('%s %s', $method, $uri);

            case 'http_response':
                $status = $this->context['statusCode'] ?? '';

                return sprintf('Status %s', $status);

            case 'database_connection':
                $host = $this->context['host'] ?? '';
                $db = $this->context['database'] ?? '';

                return sprintf('%s/%s', $host, $db);

            case 'database_query':
            case 'complex_query':
                $queryType = $this->context['queryType'] ?? '';
                $table = $this->context['table'] ?? '';

                // Add parameters info if present
                $parameters = $this->context['parameters'] ?? [];
                if (! empty($parameters) && is_array($parameters)) {
                    $paramInfo = $this->formatMultiLineData($parameters, $config);

                    return sprintf('%s %s (params: %s)', $queryType, $table, $paramInfo);
                }

                return sprintf('%s %s', $queryType, $table);

            case 'external_api_request':
                $service = $this->context['service'] ?? '';
                $endpoint = $this->context['endpoint'] ?? '';

                return sprintf('%s %s', $service, $this->shortenUrl($endpoint));

            case 'cache_operation':
                $operation = $this->context['operation'] ?? '';
                $key = $this->context['key'] ?? '';
                $hit = $this->context['hit'] ?? false ? 'HIT' : 'MISS';

                return sprintf('%s %s (%s)', $operation, $key, $hit);

            case 'file_processing':
                $operation = $this->context['operation'] ?? '';
                $filename = $this->context['filename'] ?? '';

                return sprintf('%s %s', $operation, $filename);

            case 'authentication_request':
            case 'authentication':
                $method = $this->context['method'] ?? '';
                $token = $this->context['token'] ?? null;
                $status = $token ? 'SUCCESS' : 'FAILED';

                return sprintf('%s (%s)', $method, $status);

            case 'business_logic':
                $operation = $this->context['operation'] ?? '';
                $success = $this->context['success'] ?? false ? 'SUCCESS' : 'FAILED';

                return sprintf('%s (%s)', $operation, $success);

            case 'error':
                $errorType = $this->context['errorType'] ?? '';
                $message = $this->context['message'] ?? '';

                return sprintf('%s: %s', $errorType, $this->truncateMessage($message));

            case 'performance_metrics':
                $queries = $this->context['databaseQueries'] ?? 0;
                $memory = $this->context['memoryUsed'] ?? 0;

                return sprintf('%d queries, %s memory', $queries, $this->formatBytes($memory));

            default:
                // Try to find common patterns
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
        $maxLines = $config?->maxLines ?? 5;

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
            } else {
                $items[] = is_string($key) ? "{$key}: [complex]" : '[complex]';
            }

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
                } else {
                    $items[] = is_string($key) ? "{$key}: [complex]" : '[complex]';
                }
            }

            return implode(', ', $items);
        }

        return (string) $data;
    }

    /** @codeCoverageIgnore */
    private function formatBytes(int|float $bytes): string
    {
        if (! is_numeric($bytes)) {
            return '0B';
        }

        $bytes = (float) $bytes;

        if ($bytes < 1024) {
            return sprintf('%.0fB', $bytes);
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1fKB', $bytes / 1024);
        }

        return sprintf('%.1fMB', $bytes / (1024 * 1024));
    }
}
