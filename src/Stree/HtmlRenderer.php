<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function htmlspecialchars;
use function in_array;
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

        // Check depth limits (similar to TreeRenderer logic)
        if (! $config->showFullTree && $currentDepth >= $config->maxDepth) {
            if (! in_array($node->type, $config->expandTypes, true)) {
                if ($currentDepth === $config->maxDepth) {
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

                return '';
            }
        }

        // Check time threshold
        if ($config->timeThreshold > 0 && $node->executionTime < $config->timeThreshold) {
            return '';
        }

        $hasChildren = ! empty($node->children);
        $nodeClass = $hasChildren ? 'has-children' : 'leaf-node';

        $html = sprintf(
            "%s<div class=\"tree-node %s\" data-type=\"%s\" data-time=\"%.3f\">\n",
            $indent,
            $nodeClass,
            htmlspecialchars($node->type),
            $node->executionTime,
        );

        // Node header
        $html .= sprintf("%s    <div class=\"node-header\">\n", $indent);

        if ($hasChildren) {
            $html .= sprintf("%s        <span class=\"toggle\" onclick=\"toggleNode(this)\">▼</span>\n", $indent);
        } else {
            $html .= sprintf("%s        <span class=\"toggle-placeholder\"></span>\n", $indent);
        }

        $html .= sprintf(
            "%s        <span class=\"node-type %s\">%s</span>\n",
            $indent,
            $this->getTypeClass($node->type),
            htmlspecialchars($node->type),
        );

        $contextInfo = $node->extractContextInfo($config);
        if ($contextInfo !== '') {
            $html .= sprintf(
                "%s        <span class=\"node-info\">%s</span>\n",
                $indent,
                htmlspecialchars($contextInfo),
            );
        }

        $html .= sprintf(
            "%s        <span class=\"timing %s\">%s</span>\n",
            $indent,
            $this->getTimingClass($node->executionTime),
            $this->formatExecutionTime($node->executionTime),
        );

        $html .= sprintf("%s    </div>\n", $indent); // node-header

        // Children
        if ($hasChildren) {
            $html .= sprintf("%s    <div class=\"children\">\n", $indent);
            foreach ($node->children as $child) {
                $html .= $this->renderNode($child, $config, $currentDepth + 1);
            }

            $html .= sprintf("%s    </div>\n", $indent);
        }

        $html .= sprintf("%s</div>\n", $indent); // tree-node

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
            'error_context' => 'error',
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
                $method = (string) ($node->context['method'] ?? '');
                $uri = (string) ($node->context['uri'] ?? '');

                return sprintf('%s %s', $method, $uri);

            case 'external_api_request':
                return (string) ($node->context['service'] ?? '');

            case 'database_connection':
                $host = (string) ($node->context['host'] ?? '');
                $db = (string) ($node->context['database'] ?? '');

                return sprintf('%s/%s', $host, $db);

            default:
                return '';
        }
    }

    /** @codeCoverageIgnore */
    private function getHtmlHeader(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semantic Tree Visualization</title>
    <style>
        body {
            font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
            line-height: 1.6;
            margin: 20px;
            background-color: #f8f9fa;
            color: #333;
        }
        .semantic-tree {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .tree-node {
            margin: 0;
            padding: 0;
        }
        .node-header {
            display: flex;
            align-items: center;
            padding: 4px 0;
            cursor: pointer;
            border-radius: 4px;
            transition: background-color 0.2s;
        }
        .node-header:hover {
            background-color: #f0f8ff;
        }
        .toggle {
            width: 16px;
            text-align: center;
            cursor: pointer;
            user-select: none;
            margin-right: 6px;
            color: #666;
            font-size: 12px;
        }
        .toggle-placeholder {
            width: 16px;
            margin-right: 6px;
        }
        .node-type {
            font-weight: bold;
            margin-right: 8px;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }
        .node-type.http { background-color: #e3f2fd; color: #1976d2; }
        .node-type.database { background-color: #f3e5f5; color: #7b1fa2; }
        .node-type.api { background-color: #fff3e0; color: #f57c00; }
        .node-type.auth { background-color: #e8f5e8; color: #388e3c; }
        .node-type.cache { background-color: #fff8e1; color: #f9a825; }
        .node-type.business { background-color: #fce4ec; color: #c2185b; }
        .node-type.file { background-color: #e0f2f1; color: #00796b; }
        .node-type.metrics { background-color: #f1f8e9; color: #689f38; }
        .node-type.error { background-color: #ffebee; color: #d32f2f; }
        .node-type.default { background-color: #f5f5f5; color: #616161; }
        
        .node-info {
            flex-grow: 1;
            margin-right: 8px;
            font-size: 13px;
            color: #555;
        }
        .timing {
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
        }
        .timing.fast { background-color: #e8f5e8; color: #388e3c; }
        .timing.normal { background-color: #fff8e1; color: #f9a825; }
        .timing.slow { background-color: #fff3e0; color: #f57c00; }
        .timing.very-slow { background-color: #ffebee; color: #d32f2f; }
        .timing.collapsed { background-color: #f5f5f5; color: #999; }
        
        .children {
            margin-left: 20px;
            border-left: 1px solid #e0e0e0;
            padding-left: 4px;
        }
        .children.collapsed {
            display: none;
        }
        
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <h1>🌲 Semantic Tree Visualization</h1>
HTML;
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
