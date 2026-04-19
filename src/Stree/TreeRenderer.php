<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function count;
use function explode;
use function implode;
use function strpos;
use function substr;

final class TreeRenderer
{
    private const TREE_VERTICAL = '│';
    private const TREE_BRANCH = '├';
    private const TREE_LAST = '└';
    private const TREE_HORIZONTAL = '─';
    private const TREE_SPACE = ' ';
    private const CLOSE_MARK = '⎿';

    /** @param array<string, mixed> $logData */
    public function render(array $logData, RenderConfig $config): string
    {
        $parser = new LogDataParser();
        $root = $parser->parseLogData($logData);

        // Propagate status up the tree
        $this->propagateStatus($root);

        return $this->renderRoot($root, $config);
    }

    /**
     * Render the root node flush-left (no synthetic "session" header) followed by
     * events/nested-opens as children, and finally the root's close signals on a
     * "⎿" continuation line. Multi-line display (formatter-emitted open + its own
     * continuation) is split so both lines sit flush-left.
     */
    private function renderRoot(TreeNode $root, RenderConfig $config): string
    {
        $lines = [];

        if ($config->timeThreshold > 0 && $root->executionTime < $config->timeThreshold) {
            return '';
        }

        $displayLine = $root->getDisplayLine($config);
        $parts = explode("\n", $displayLine, 2);
        $lines[] = $parts[0];
        if (isset($parts[1])) {
            $lines[] = $parts[1];
        }

        if ($config->showFullTree) {
            $this->renderFullModeLeaves($root, $lines, '');
        }

        $closeSignals = $root->getCloseSignals();
        $hasCloseLine = $closeSignals !== '';

        $totalChildren = count($root->children);
        for ($i = 0; $i < $totalChildren; $i++) {
            $child = $root->children[$i];
            $isLastChild = ($i === $totalChildren - 1) && ! $config->showFullTree && ! $hasCloseLine;
            $this->renderNode($child, $lines, '', $isLastChild, $config);
        }

        if ($hasCloseLine) {
            $lines[] = self::CLOSE_MARK . ' ' . $closeSignals;
        }

        return implode("\n", $lines);
    }

    /** @param string[] $lines */
    private function renderNode(
        TreeNode $node,
        array &$lines,
        string $prefix,
        bool $isLast,
        RenderConfig $config,
    ): void {
        if ($config->timeThreshold > 0 && $node->executionTime < $config->timeThreshold) {
            return;
        }

        $symbol = $isLast ? self::TREE_LAST : self::TREE_BRANCH;
        $displayLine = $node->getDisplayLine($config);
        $childPrefix = $prefix . ($isLast ? self::TREE_SPACE : self::TREE_VERTICAL) . self::TREE_SPACE . self::TREE_SPACE . self::TREE_SPACE;

        // Open line (plus optional continuation from a formatter that emitted "open\ncontinuation").
        $parts = explode("\n", $displayLine, 2);
        $lines[] = $prefix . $symbol . self::TREE_HORIZONTAL . self::TREE_HORIZONTAL . ' ' . $parts[0];
        if (isset($parts[1])) {
            $lines[] = $childPrefix . $parts[1];
        }

        if ($config->showFullTree) {
            $this->renderFullModeLeaves($node, $lines, $childPrefix);
        }

        $closeSignals = $node->getCloseSignals();
        $hasCloseLine = $closeSignals !== '';

        $totalChildren = count($node->children);
        for ($i = 0; $i < $totalChildren; $i++) {
            $child = $node->children[$i];
            $isLastChild = ($i === $totalChildren - 1) && ! $config->showFullTree && ! $hasCloseLine;
            $this->renderNode($child, $lines, $childPrefix, $isLastChild, $config);
        }

        if ($hasCloseLine) {
            $lines[] = $childPrefix . self::CLOSE_MARK . ' ' . $closeSignals;
        }
    }

    /**
     * In full mode, render all context keys as leaf nodes under the current node.
     *
     * @param string[] $lines
     */
    private function renderFullModeLeaves(TreeNode $node, array &$lines, string $prefix): void
    {
        $extractor = new SignalExtractor();
        $openLeaves = $extractor->expandFull($node->context);

        // Close-only or close-changed keys as leaves with → prefix
        $closeLeaves = [];
        if ($node->closeType !== null) {
            $closeLeaves = $this->expandCloseDiff($node->context, $node->closeContext);
        }

        $allLeaves = [];
        foreach ($openLeaves as $leaf) {
            $allLeaves[] = $leaf;
        }

        foreach ($closeLeaves as $leaf) {
            $allLeaves[] = '→ ' . $leaf;
        }

        $hasChildren = count($node->children) > 0;
        $totalLeaves = count($allLeaves);
        for ($i = 0; $i < $totalLeaves; $i++) {
            $isLastLeaf = ($i === $totalLeaves - 1) && ! $hasChildren;
            $symbol = $isLastLeaf ? self::TREE_LAST : self::TREE_BRANCH;
            $lines[] = $prefix . $symbol . self::TREE_HORIZONTAL . self::TREE_HORIZONTAL . ' ' . $allLeaves[$i];
        }
    }

    /**
     * Expand close context into diff leaves (close-only or changed keys).
     *
     * @param  array<string, mixed> $openContext
     * @param  array<string, mixed> $closeContext
     *
     * @return string[]
     */
    private function expandCloseDiff(array $openContext, array $closeContext): array
    {
        $extractor = new SignalExtractor();
        $openLeaves = $extractor->expandFull($openContext);
        $closeLeaves = $extractor->expandFull($closeContext);

        // Build open key→value map
        $openMap = [];
        foreach ($openLeaves as $leaf) {
            $pos = strpos($leaf, ': ');
            if ($pos !== false) {
                $openMap[substr($leaf, 0, $pos)] = substr($leaf, $pos + 2);
            }
        }

        $diffs = [];
        foreach ($closeLeaves as $leaf) {
            $pos = strpos($leaf, ': ');
            if ($pos === false) {
                continue;
            }

            $key = substr($leaf, 0, $pos);
            $val = substr($leaf, $pos + 2);

            if (! isset($openMap[$key])) {
                $diffs[] = $leaf;
            } elseif ($openMap[$key] !== $val) {
                $diffs[] = $key . ': ' . $openMap[$key] . '→' . $val;
            }
        }

        return $diffs;
    }

    /**
     * Propagate status upward through the tree.
     * Sets 'unclosed' on open nodes without close, 'failed' on failure indicators,
     * and propagates 'failed' upward.
     */
    private function propagateStatus(TreeNode $node): void
    {
        $extractor = new SignalExtractor();

        // First, recurse into children to set their status
        foreach ($node->children as $child) {
            $this->propagateStatus($child);
        }

        if ($node->closeType === null && ! $node->isEvent) {
            $node->status = 'unclosed';

            return;
        }

        if ($node->closeType !== null && $extractor->hasFailureIndicator($node->closeContext)) {
            $node->status = 'Failed';

            return;
        }

        foreach ($node->children as $child) {
            if ($child->status === 'Failed') {
                $node->status = 'Failed';
                break;
            }
        }
    }
}
