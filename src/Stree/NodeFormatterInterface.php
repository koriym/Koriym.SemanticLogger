<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

interface NodeFormatterInterface
{
    /**
     * Return the display line for this node.
     *
     * May embed a single "\n" to emit a continuation line that the renderer
     * places directly under the first line with matching child indentation.
     */
    public function format(TreeNode $node, RenderConfig $config): string;
}
