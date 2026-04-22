<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree\Fake;

use Koriym\SemanticLogger\Stree\NodeFormatterInterface;
use Koriym\SemanticLogger\Stree\RenderConfig;
use Koriym\SemanticLogger\Stree\TreeNode;

final class FakeFormatter implements NodeFormatterInterface
{
    public function format(TreeNode $node, RenderConfig $config): string
    {
        $open = 'fake open=' . $node->type;
        if ($config->showValues) {
            $open .= ' values=on';
        }

        if ($node->closeType === null) {
            return $open;
        }

        return $open . "\n└── close=" . $node->closeType;
    }
}
