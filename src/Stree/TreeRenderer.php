<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function count;
use function implode;
use function in_array;

final class TreeRenderer
{
    private const TREE_SYMBOLS = [
        'vertical' => '│',
        'branch' => '├',
        'last' => '└',
        'horizontal' => '─',
        'space' => ' ',
    ];

    /** @param array<string, mixed> $logData */
    public function render(array $logData, RenderConfig $config): string
    {
        $parser = new LogDataParser();
        $tree = $parser->parseLogData($logData);

        return $this->renderTree($tree, $config);
    }

    private function renderTree(TreeNode $tree, RenderConfig $config): string
    {
        $lines = [];
        $this->renderNode($tree, $lines, '', true, $config, 0);

        return implode("\n", $lines);
    }

    /** @param string[] $lines */
    private function renderNode(
        TreeNode $node,
        array &$lines,
        string $prefix,
        bool $isLast,
        RenderConfig $config,
        int $currentDepth,
    ): void {
        // Check depth limits
        if (! $config->showFullTree && $currentDepth >= $config->maxDepth) {
            // Allow expansion of specific types
            if (! in_array($node->type, $config->expandTypes, true)) {
                if ($currentDepth === $config->maxDepth) {
                    $symbol = $isLast ? self::TREE_SYMBOLS['last'] : self::TREE_SYMBOLS['branch'];
                    $lines[] = $prefix . $symbol . self::TREE_SYMBOLS['horizontal'] . self::TREE_SYMBOLS['horizontal'] . ' ' . $node->getDisplayName() . ' [...]';
                }

                return;
            }
        }

        // Check time threshold
        if ($config->timeThreshold > 0 && $node->executionTime < $config->timeThreshold) {
            return;
        }

        // Render current node
        $symbol = $isLast ? self::TREE_SYMBOLS['last'] : self::TREE_SYMBOLS['branch'];
        $nodeDisplay = $prefix . $symbol . self::TREE_SYMBOLS['horizontal'] . self::TREE_SYMBOLS['horizontal'] . ' ' . $node->getDisplayLine($config);
        $lines[] = $nodeDisplay;

        // Update prefix for children
        $childPrefix = $prefix . ($isLast ? self::TREE_SYMBOLS['space'] : self::TREE_SYMBOLS['vertical']) . self::TREE_SYMBOLS['space'] . self::TREE_SYMBOLS['space'] . self::TREE_SYMBOLS['space'];

        // Render children
        $totalChildren = count($node->children);
        for ($i = 0; $i < $totalChildren; $i++) {
            $child = $node->children[$i];
            $isLastChild = ($i === $totalChildren - 1);
            $this->renderNode($child, $lines, $childPrefix, $isLastChild, $config, $currentDepth + 1);
        }
    }
}
