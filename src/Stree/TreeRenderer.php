<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function array_key_exists;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;

final class TreeRenderer
{
    private const TREE_VERTICAL = '│';
    private const TREE_BRANCH = '├';
    private const TREE_LAST = '└';
    private const TREE_HORIZONTAL = '─';
    private const TREE_SPACE = ' ';

    /** @param array<string, mixed> $logData */
    public function render(array $logData, RenderConfig $config): string
    {
        $parser = new LogDataParser();
        $root = $parser->parseLogData($logData);

        // Propagate status up the tree
        $this->propagateStatus($root);

        if ($root->isSynthetic) {
            return $this->renderForest($root, $config);
        }

        return $this->renderTopLevelNode($root, $config);
    }

    /** Render top-level sibling nodes flush-left (no synthetic "session" header). */
    private function renderForest(TreeNode $root, RenderConfig $config): string
    {
        /** @var list<string> $lines */
        $lines = [];
        $totalChildren = count($root->children);
        for ($i = 0; $i < $totalChildren; $i++) {
            $child = $root->children[$i];
            $this->renderTopLevelNodeInto($child, $lines, $config);
        }

        return implode("\n", $lines);
    }

    /**
     * Render the root node flush-left (no synthetic "session" header) followed by
     * events/nested-opens as children, and finally the root's close signals on a
     * tree-style close branch. Multi-line display (formatter-emitted open + its own
     * continuation) is split so both lines sit flush-left.
     */
    private function renderTopLevelNode(TreeNode $root, RenderConfig $config): string
    {
        /** @var list<string> $lines */
        $lines = [];
        $this->renderTopLevelNodeInto($root, $lines, $config);

        return implode("\n", $lines);
    }

    /** @param array<string> $lines */
    private function renderTopLevelNodeInto(TreeNode $root, array &$lines, RenderConfig $config): void
    {
        if ($config->timeThreshold > 0 && $root->executionTime < $config->timeThreshold) {
            return;
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
        $hasCloseLine = ! $config->showFullTree && $closeSignals !== '';

        $totalChildren = count($root->children);
        for ($i = 0; $i < $totalChildren; $i++) {
            $child = $root->children[$i];
            $isLastChild = ($i === $totalChildren - 1) && ! $config->showFullTree && ! $hasCloseLine;
            $this->renderNode($child, $lines, '', $isLastChild, $config);
        }

        if ($config->showFullTree) {
            $this->renderFullModeClose($root, $lines, '');
        } elseif ($hasCloseLine) {
            $lines[] = self::TREE_LAST . self::TREE_HORIZONTAL . self::TREE_HORIZONTAL . ' ' . $closeSignals;
        }
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
        $hasCloseLine = ! $config->showFullTree && $closeSignals !== '';

        $totalChildren = count($node->children);
        for ($i = 0; $i < $totalChildren; $i++) {
            $child = $node->children[$i];
            $isLastChild = ($i === $totalChildren - 1) && ! $config->showFullTree && ! $hasCloseLine;
            $this->renderNode($child, $lines, $childPrefix, $isLastChild, $config);
        }

        if ($config->showFullTree) {
            $this->renderFullModeClose($node, $lines, $childPrefix);
        } elseif ($hasCloseLine) {
            $lines[] = $childPrefix . self::TREE_LAST . self::TREE_HORIZONTAL . self::TREE_HORIZONTAL . ' ' . $closeSignals;
        }
    }

    /**
     * In full mode, render all context keys as leaf nodes under the current node.
     *
     * @param string[] $lines
     */
    private function renderFullModeLeaves(TreeNode $node, array &$lines, string $prefix): void
    {
        $openLeaves = $this->extractFullModeOpenLeaves($node);

        $hasChildren = count($node->children) > 0 || $node->closeType !== null;
        $totalLeaves = count($openLeaves);
        for ($i = 0; $i < $totalLeaves; $i++) {
            $isLastLeaf = ($i === $totalLeaves - 1) && ! $hasChildren;
            $symbol = $isLastLeaf ? self::TREE_LAST : self::TREE_BRANCH;
            $lines[] = $prefix . $symbol . self::TREE_HORIZONTAL . self::TREE_HORIZONTAL . ' ' . $openLeaves[$i];
        }
    }

    /**
     * Render a semantic close branch in full mode, preserving the open/close tree
     * shape instead of flattening close data into generic JSON leaves.
     *
     * @param string[] $lines
     */
    private function renderFullModeClose(TreeNode $node, array &$lines, string $prefix): void
    {
        if ($node->closeType === null) {
            return;
        }

        $extractor = new SignalExtractor();
        $closeLeaves = $this->extractFullModeCloseLeaves($node);
        $profileLeaves = $extractor->expandFull($node->closeProfile);

        $lines[] = $prefix . self::TREE_LAST . self::TREE_HORIZONTAL . self::TREE_HORIZONTAL . ' close';
        $closeChildPrefix = $prefix . self::TREE_SPACE . self::TREE_SPACE . self::TREE_SPACE . self::TREE_SPACE;

        $closeItemCount = count($closeLeaves) + ($profileLeaves !== [] ? 1 : 0);
        $leafIndex = 0;
        foreach ($closeLeaves as $leaf) {
            $leafIndex++;
            $isLastLeaf = $leafIndex === $closeItemCount;
            $symbol = $isLastLeaf ? self::TREE_LAST : self::TREE_BRANCH;
            $lines[] = $closeChildPrefix . $symbol . self::TREE_HORIZONTAL . self::TREE_HORIZONTAL . ' ' . $leaf;
        }

        if ($profileLeaves === []) {
            return;
        }

        $lines[] = $closeChildPrefix . self::TREE_LAST . self::TREE_HORIZONTAL . self::TREE_HORIZONTAL . ' profile';
        $profilePrefix = $closeChildPrefix . self::TREE_SPACE . self::TREE_SPACE . self::TREE_SPACE;
        $profileLeafCount = count($profileLeaves);
        for ($i = 0; $i < $profileLeafCount; $i++) {
            $isLastLeaf = $i === $profileLeafCount - 1;
            $symbol = $isLastLeaf ? self::TREE_LAST : self::TREE_BRANCH;
            $lines[] = $profilePrefix . $symbol . self::TREE_HORIZONTAL . self::TREE_HORIZONTAL . ' ' . $profileLeaves[$i];
        }
    }

    /** @return string[] */
    private function extractFullModeOpenLeaves(TreeNode $node): array
    {
        $extractor = new SignalExtractor();

        return match ($node->type) {
            'becoming_open' => $this->expandFlatPropertyLeaves($node->context['prop'] ?? []),
            'being_open', 'being_final_open' => $this->expandAuxiliaryOpenLeaves($node->context),
            default => $extractor->expandFull($node->context),
        };
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return string[]
     */
    private function expandAuxiliaryOpenLeaves(array $context): array
    {
        $extractor = new SignalExtractor();
        if (! array_key_exists('inject', $context) || ! is_array($context['inject'])) {
            return [];
        }

        /** @var array<string, mixed> $inject */
        $inject = $context['inject'];

        return $extractor->expandFull($inject, 'inject');
    }

    /** @return string[] */
    private function extractFullModeCloseLeaves(TreeNode $node): array
    {
        $context = $node->closeContext;
        $extractor = new SignalExtractor();

        return match ($node->type) {
            'becoming_open' => $this->expandSemanticCloseLeaves($context, ['final']),
            'being_open', 'being_final_open' => $this->expandSemanticCloseLeaves($context, ['final', 'being']),
            default => $extractor->expandFull($context),
        };
    }

    /**
     * @param array<string, mixed> $context
     * @param string[]             $excludedKeys
     *
     * @return string[]
     */
    private function expandSemanticCloseLeaves(array $context, array $excludedKeys): array
    {
        $extractor = new SignalExtractor();
        $lines = [];

        /** @var mixed $value */
        foreach ($context as $key => $value) {
            if (in_array($key, $excludedKeys, true)) {
                continue;
            }

            if ($key === 'prop' && is_array($value)) {
                $lines = [...$lines, ...$this->expandFlatPropertyLeaves($value)];

                continue;
            }

            $lines = [...$lines, ...$extractor->expandFull([$key => $value])];
        }

        return $lines;
    }

    /** @return string[] */
    private function expandFlatPropertyLeaves(mixed $props): array
    {
        if (! is_array($props)) {
            return [];
        }

        /** @var array<string, mixed> $props */
        return (new SignalExtractor())->expandFull($props);
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

        if ($node->closeType === null && ! $node->isEvent && ! $node->isOrphanClose) {
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
