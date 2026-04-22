<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function strlen;
use function substr;

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
     * Return close-diff signals as a space-joined string, or empty when the
     * node has no close or no signals differ from open. The renderer decorates
     * this with a close branch and places it directly beneath the children.
     */
    public function getCloseSignals(): string
    {
        if ($this->closeType === null) {
            return '';
        }

        return (new SignalExtractor())->extractCloseDiff($this->context, $this->closeContext);
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
