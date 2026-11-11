<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree\Fake;

use Koriym\SemanticLogger\Stree\NodeFormatterInterface;
use Koriym\SemanticLogger\Stree\RenderConfig;
use Koriym\SemanticLogger\Stree\TreeNode;

final class FakeFormatterWithArgument implements NodeFormatterInterface
{
    public function __construct(private readonly string $label)
    {
    }

    public function format(TreeNode $node, RenderConfig $config): string
    {
        return $this->label;
    }
}
