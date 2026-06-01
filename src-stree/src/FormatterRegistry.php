<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

final class FormatterRegistry
{
    /** @var array<string, NodeFormatterInterface> */
    private array $map = [];

    public function register(string $type, NodeFormatterInterface $formatter): void
    {
        $this->map[$type] = $formatter;
    }

    public function get(string $type): NodeFormatterInterface|null
    {
        return $this->map[$type] ?? null;
    }
}
