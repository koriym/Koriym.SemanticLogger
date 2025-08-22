<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function file_exists;
use function file_get_contents;
use function htmlspecialchars;
use function in_array;
use function is_scalar;
use function sprintf;
use function str_repeat;

final class HtmlRenderer
{
    public function render(TreeNode $tree, RenderConfig $config): string
    {
        $html = $this->getHtmlHeader();
        $html .= "<div class=\"semantic-tree\">\n";
        $html .= $this->renderNode($tree, $config, 1);
        $html .= "</div>\n";
        $html .= $this->getHtmlFooter();

        return $html;
    }

    /** @codeCoverageIgnore */
    private function renderNode(TreeNode $node, RenderConfig $config, int $currentDepth): string
    {
        $indent = str_repeat('    ', $currentDepth);

        // Early returns for filtering
        $depthLimitResult = $this->checkDepthLimits($node, $config, $currentDepth, $indent);
        if ($depthLimitResult !== null) {
            return $depthLimitResult;
        }

        if ($config->timeThreshold > 0 && $node->executionTime < $config->timeThreshold) {
            return '';
        }

        return $this->renderFullNode($node, $config, $currentDepth, $indent);
    }

    /** @codeCoverageIgnore */
    private function checkDepthLimits(TreeNode $node, RenderConfig $config, int $currentDepth, string $indent): string|null
    {
        if (! $config->showFullTree && $currentDepth >= $config->maxDepth) {
            if (! in_array($node->type, $config->expandTypes, true)) {
                if ($currentDepth === $config->maxDepth) {
                    return $this->renderCollapsedNode($node, $indent);
                }

                return '';
            }
        }

        return null;
    }

    /** @codeCoverageIgnore */
    private function renderCollapsedNode(TreeNode $node, string $indent): string
    {
        return sprintf(
            "%s<div class=\"tree-node collapsed\" data-type=\"%s\">\n" .
            "%s    <div class=\"node-header\">\n" .
            "%s        <span class=\"node-type %s\">%s</span>\n" .
            "%s        <span class=\"node-info\">%s</span>\n" .
            "%s        <span class=\"timing collapsed\">[...]</span>\n" .
            "%s    </div>\n" .
            "%s</div>\n",
            $indent,
            htmlspecialchars($node->type),
            $indent,
            $indent,
            $this->getTypeClass($node->type),
            htmlspecialchars($node->type),
            $indent,
            htmlspecialchars($this->extractSimpleInfo($node)),
            $indent,
            $indent,
            $indent,
        );
    }

    /** @codeCoverageIgnore */
    private function renderFullNode(TreeNode $node, RenderConfig $config, int $currentDepth, string $indent): string
    {
        $hasChildren = ! empty($node->children);
        $nodeClass = $hasChildren ? 'has-children' : 'leaf-node';

        $html = sprintf(
            "%s<div class=\"tree-node %s\" data-type=\"%s\" data-time=\"%.3f\">\n",
            $indent,
            $nodeClass,
            htmlspecialchars($node->type),
            $node->executionTime,
        );

        $html .= $this->renderNodeHeader($node, $config, $indent, $hasChildren);
        $html .= $this->renderChildren($node, $config, $currentDepth, $indent, $hasChildren);
        $html .= sprintf("%s</div>\n", $indent);

        return $html;
    }

    /** @codeCoverageIgnore */
    private function renderNodeHeader(TreeNode $node, RenderConfig $config, string $indent, bool $hasChildren): string
    {
        $html = sprintf("%s    <div class=\"node-header\">\n", $indent);
        $html .= $this->renderToggle($indent, $hasChildren);
        $html .= $this->renderNodeType($node, $indent);
        $html .= $this->renderContextInfo($node, $config, $indent);
        $html .= $this->renderTiming($node, $indent);
        $html .= sprintf("%s    </div>\n", $indent);

        return $html;
    }

    /** @codeCoverageIgnore */
    private function renderToggle(string $indent, bool $hasChildren): string
    {
        if ($hasChildren) {
            return sprintf("%s        <span class=\"toggle\" onclick=\"toggleNode(this)\">▼</span>\n", $indent);
        }

        return sprintf("%s        <span class=\"toggle-placeholder\"></span>\n", $indent);
    }

    /** @codeCoverageIgnore */
    private function renderNodeType(TreeNode $node, string $indent): string
    {
        return sprintf(
            "%s        <span class=\"node-type %s\">%s</span>\n",
            $indent,
            $this->getTypeClass($node->type),
            htmlspecialchars($node->type),
        );
    }

    /** @codeCoverageIgnore */
    private function renderContextInfo(TreeNode $node, RenderConfig $config, string $indent): string
    {
        $contextInfo = $node->extractContextInfo($config);
        if ($contextInfo !== '') {
            return sprintf(
                "%s        <span class=\"node-info\">%s</span>\n",
                $indent,
                htmlspecialchars($contextInfo),
            );
        }

        return '';
    }

    /** @codeCoverageIgnore */
    private function renderTiming(TreeNode $node, string $indent): string
    {
        return sprintf(
            "%s        <span class=\"timing %s\">%s</span>\n",
            $indent,
            $this->getTimingClass($node->executionTime),
            $this->formatExecutionTime($node->executionTime),
        );
    }

    /** @codeCoverageIgnore */
    private function renderChildren(TreeNode $node, RenderConfig $config, int $currentDepth, string $indent, bool $hasChildren): string
    {
        if (! $hasChildren) {
            return '';
        }

        $html = sprintf("%s    <div class=\"children\">\n", $indent);
        foreach ($node->children as $child) {
            $html .= $this->renderNode($child, $config, $currentDepth + 1);
        }

        $html .= sprintf("%s    </div>\n", $indent);

        return $html;
    }

    /** @codeCoverageIgnore */
    private function getTypeClass(string $type): string
    {
        return match ($type) {
            'http_request', 'http_response' => 'http',
            'database_connection', 'database_query', 'complex_query' => 'database',
            'external_api_request' => 'api',
            'authentication_request', 'authentication' => 'auth',
            'cache_operation' => 'cache',
            'business_logic' => 'business',
            'file_processing' => 'file',
            'performance_metrics' => 'metrics',
            'error', 'error_context' => 'error',
            default => 'default'
        };
    }

    /** @codeCoverageIgnore */
    private function getTimingClass(float $time): string
    {
        if ($time < 0.1) {
            return 'fast';
        }

        if ($time < 0.5) {
            return 'normal';
        }

        if ($time < 1.0) {
            return 'slow';
        }

        return 'very-slow';
    }

    /** @codeCoverageIgnore */
    private function formatExecutionTime(float $time): string
    {
        if ($time < 0.001) {
            return sprintf('[%.1fμs]', $time * 1_000_000);
        }

        if ($time < 1.0) {
            return sprintf('[%.1fms]', $time * 1000);
        }

        return sprintf('[%.1fs]', $time);
    }

    /** @codeCoverageIgnore */
    private function extractSimpleInfo(TreeNode $node): string
    {
        switch ($node->type) {
            case 'http_request':
                $methodValue = $node->context['method'] ?? null;
                $uriValue = $node->context['uri'] ?? null;
                $method = is_scalar($methodValue) ? (string) $methodValue : '';
                $uri = is_scalar($uriValue) ? (string) $uriValue : '';

                return sprintf('%s %s', $method, $uri);

            case 'external_api_request':
                $serviceValue = $node->context['service'] ?? null;

                return is_scalar($serviceValue) ? (string) $serviceValue : '';

            case 'database_connection':
                $hostValue = $node->context['host'] ?? null;
                $dbValue = $node->context['database'] ?? null;
                $host = is_scalar($hostValue) ? (string) $hostValue : '';
                $db = is_scalar($dbValue) ? (string) $dbValue : '';

                return sprintf('%s/%s', $host, $db);

            default:
                return '';
        }
    }

    /** @codeCoverageIgnore */
    private function getHtmlHeader(): string
    {
        $cssPath = __DIR__ . '/../../docs/css/semantic-tree.css';
        $css = file_exists($cssPath) ? file_get_contents($cssPath) : $this->getFallbackCss();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semantic Tree Visualization</title>
    <style>
{$css}
    </style>
</head>
<body>
    <h1>🌲 Semantic Tree Visualization</h1>
HTML;
    }

    /** @codeCoverageIgnore */
    private function getFallbackCss(): string
    {
        return 'body { font-family: monospace; margin: 20px; }';
    }

    /** @codeCoverageIgnore */
    private function getHtmlFooter(): string
    {
        return <<<'HTML'
    <script>
        function toggleNode(toggleElement) {
            const nodeHeader = toggleElement.parentElement;
            const treeNode = nodeHeader.parentElement;
            const children = treeNode.querySelector('.children');
            
            if (children) {
                const isCollapsed = children.classList.contains('collapsed');
                
                if (isCollapsed) {
                    children.classList.remove('collapsed');
                    toggleElement.textContent = '▼';
                } else {
                    children.classList.add('collapsed');
                    toggleElement.textContent = '▶';
                }
            }
        }
        
        // Initialize with some nodes collapsed for better overview
        document.addEventListener('DOMContentLoaded', function() {
            const deepNodes = document.querySelectorAll('.tree-node[data-time]');
            deepNodes.forEach((node, index) => {
                if (index > 5) { // Collapse nodes after the first few
                    const children = node.querySelector('.children');
                    const toggle = node.querySelector('.toggle');
                    if (children && toggle) {
                        children.classList.add('collapsed');
                        toggle.textContent = '▶';
                    }
                }
            });
        });
    </script>
</body>
</html>
HTML;
    }
}
