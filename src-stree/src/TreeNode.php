<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function strlen;
use function substr;
use function usort;

final class TreeNode
{
    /** @var TreeNode[] */
    public array $children = [];

    /** @var array<string, mixed> */
    public array $closeContext = [];

    /** @var array<string, mixed> */
    public array $closeProfile = [];
    public string|null $closeType = null;

    /** Whether this node was produced from the events section */
    public bool $isEvent = false;

    /** Whether this node was produced from a top-level or unmatched close entry */
    public bool $isOrphanClose = false;

    /** Status: 'failed' | 'unclosed' | '' */
    public string $status = '';
    public bool $isSynthetic = false;

    /**
     * @param array<string, mixed> $context
     * @param int|null             $seq     Record-order position from the log document; null when the document predates `seq` or the node is synthetic.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $context,
        public readonly float $executionTime = 0.0,
        public readonly TreeNode|null $parent = null,
        public readonly int|null $seq = null,
    ) {
    }

    /** @param array<string, mixed> $context */
    public static function synthetic(
        string $id,
        string $type,
        array $context,
        float $executionTime = 0.0,
        TreeNode|null $parent = null,
    ): self {
        $node = new self($id, $type, $context, $executionTime, $parent);
        $node->isSynthetic = true;

        return $node;
    }

    public function addChild(TreeNode $child): void
    {
        $this->children[] = $child;
    }

    /**
     * Put children in the order the logger recorded them.
     *
     * The document keeps a scope's events and its nested opens in separate
     * arrays, so structural order shows every event before every child scope
     * regardless of what happened. When every child carries `seq` the recorded
     * order is knowable, and this restores it. Mixed or absent `seq` leaves the
     * structural order alone — a partial reordering would be a third order,
     * true to neither.
     */
    public function sortChildrenByRecordOrder(): void
    {
        foreach ($this->children as $child) {
            $child->sortChildrenByRecordOrder();
        }

        foreach ($this->children as $child) {
            if ($child->seq === null) {
                return;
            }
        }

        usort($this->children, static fn (TreeNode $a, TreeNode $b): int => $a->seq <=> $b->seq);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $profile
     */
    public function setClose(string $type, array $context, array $profile = []): void
    {
        $this->closeType = $type;
        $this->closeContext = $context;
        $this->closeProfile = $profile;
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
        $timeDisplay = $this->formatExecutionTime();
        $line = $this->semanticDisplayLine($extractor) ?? $this->genericDisplayLine($extractor);

        if ($this->executionTime > 0.0) {
            $line .= ' [' . $timeDisplay . ']';
        }

        if ($this->status !== '') {
            $line .= ' : ' . $this->status;
        }

        if ($this->isEvent) {
            $line .= ' [event]';
        } elseif ($this->isOrphanClose) {
            $line .= ' [orphan close]';
        }

        return $line;
    }

    private function genericDisplayLine(SignalExtractor $extractor): string
    {
        $displayType = $this->stripOpenSuffix($this->type);
        $parts = [$displayType];

        $signals = $extractor->extractSignals($this->context);
        if ($signals !== '') {
            $parts[] = $signals;
        }

        return implode(' ', $parts);
    }

    /**
     * Return the close entry as one line: its type, then the close-diff signals
     * (keys that differ from open or are new in close). Empty only when the node
     * never closed. The renderer decorates this with a close branch and places it
     * directly beneath the children.
     */
    public function getCloseLine(): string
    {
        if ($this->closeType === null) {
            return '';
        }

        $signals = (new SignalExtractor())->extractCloseDiff($this->context, $this->closeContext);
        if ($signals === '') {
            return $this->closeType;
        }

        return $this->closeType . ' ' . $signals;
    }

    private function semanticDisplayLine(SignalExtractor $extractor): string|null
    {
        return match ($this->type) {
            'becoming_open' => $this->formatBecomingDisplayLine($extractor),
            'being_open' => $this->formatBeingDisplayLine($extractor, 'being', 'be'),
            'being_final_open' => $this->formatBeingDisplayLine($extractor, 'being_final', 'final'),
            default => null,
        };
    }

    private function formatBecomingDisplayLine(SignalExtractor $extractor): string|null
    {
        $input = $this->context['input'] ?? null;
        if (! is_string($input)) {
            return null;
        }

        $shortInput = $extractor->formatValue($input) ?? $input;

        return 'becoming ' . $shortInput;
    }

    private function formatBeingDisplayLine(SignalExtractor $extractor, string $label, string $targetKey): string|null
    {
        $target = $this->context[$targetKey] ?? null;
        if (! is_string($target)) {
            return null;
        }

        $parts = [
            $label,
            $extractor->formatValue($target) ?? $target,
        ];

        foreach (['input', 'inject'] as $key) {
            $value = $this->context[$key] ?? null;
            if (! is_array($value) || $value === []) {
                continue;
            }

            $formatted = $extractor->formatValue($value);
            if ($formatted === null) {
                continue;
            }

            $parts[] = $key . '=' . $formatted;
        }

        return implode(' ', $parts);
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
