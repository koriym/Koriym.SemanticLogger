<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function implode;
use function sprintf;
use function strlen;
use function substr;

final class TreeNode
{
    /** @var TreeNode[] */
    public array $children = [];

    /** @var array<string, mixed> */
    public array $closeContext = [];
    public string|null $closeType = null;

    /** Whether this node was produced from the events section */
    public bool $isEvent = false;

    /** Status: 'failed' | 'unclosed' | '' */
    public string $status = '';

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

    /** @param array<string, mixed> $context */
    public function setClose(string $type, array $context): void
    {
        $this->closeType = $type;
        $this->closeContext = $context;
    }

    public function getDisplayName(): string
    {
        return $this->stripOpenSuffix($this->type);
    }

    public function getDisplayLine(RenderConfig|null $config = null): string
    {
        if ($config !== null) {
            $formatter = $config->formatters?->get($this->type);
            if ($formatter !== null) {
                return $formatter->format($this, $config);
            }
        }

        $extractor = new SignalExtractor();
        $displayType = $this->stripOpenSuffix($this->type);
        $timeDisplay = $this->formatExecutionTime();

        // Build compact 1-line: <type> <signals>[ → <close-diff>][ [timing]][ : <status>][ [event]]
        $parts = [$displayType];

        $signals = $extractor->extractSignals($this->context);
        if ($signals !== '') {
            $parts[] = $signals;
        }

        if ($this->closeType !== null) {
            $closeDiff = $extractor->extractCloseDiff($this->context, $this->closeContext);
            if ($closeDiff !== '') {
                $parts[] = $closeDiff;
            }
        }

        $line = implode(' ', $parts);

        // Timing
        if ($this->executionTime > 0.0) {
            $line .= ' [' . $timeDisplay . ']';
        }

        // Status
        if ($this->status !== '') {
            $line .= ' : ' . $this->status;
        }

        // Event marker
        if ($this->isEvent) {
            $line .= ' [event]';
        }

        return $line;
    }

    private function stripOpenSuffix(string $type): string
    {
        if ($this->closeType === null) {
            return $type;
        }

        $suffix = '_open';
        $len = strlen($suffix);
        if (strlen($type) > $len && substr($type, -$len) === $suffix) {
            return substr($type, 0, -$len);
        }

        return $type;
    }

    private function formatExecutionTime(): string
    {
        if ($this->executionTime < 0.001) {
            return sprintf('%.1fμs', $this->executionTime * 1_000_000.0);
        }

        if ($this->executionTime < 1.0) {
            return sprintf('%.1fms', $this->executionTime * 1000.0);
        }

        return sprintf('%.1fs', $this->executionTime);
    }
}
