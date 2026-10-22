<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use Koriym\SemanticLogger\Stree\Exception\RuntimeException;

use function array_combine;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function is_array;
use function is_numeric;
use function is_scalar;

/**
 * Builds the render tree from the public log shape. A log with exactly one
 * root open and no orphan close renders from that open; anything else gets a
 * synthetic forest root so every root entry sits flush-left.
 */
final class LogDataParser
{
    private int $orphanCloseSequence = 0;

    /** @param array<string, mixed> $logData */
    public function parseLogData(array $logData): TreeNode
    {
        $this->orphanCloseSequence = 0;

        if (! array_key_exists('log', $logData) || ! is_array($logData['log'])) {
            throw new RuntimeException('Invalid log data: missing log section');
        }

        $rootItems = $this->arrayItems($logData['log']);
        $hasOrphan = false;
        foreach ($rootItems as $rootItem) {
            if ($this->isOrphan($rootItem)) {
                $hasOrphan = true;
                break;
            }
        }

        if (count($rootItems) === 1 && ! $hasOrphan && $this->isInterval($rootItems[0])) {
            return $this->parseOpen($rootItems[0], null);
        }

        $forestRoot = TreeNode::synthetic('__forest__', '__forest__', []);
        foreach ($rootItems as $rootItem) {
            if ($this->isOrphan($rootItem)) {
                $forestRoot->addChild($this->createOrphanCloseNode($rootItem, $forestRoot));
                continue;
            }

            $forestRoot->addChild(
                $this->isInterval($rootItem) ? $this->parseOpen($rootItem, $forestRoot) : $this->parseEvent($rootItem, $forestRoot),
            );
        }

        return $forestRoot;
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return list<array<string, mixed>>
     */
    private function arrayItems(array $items): array
    {
        $result = [];
        /** @var mixed $item */
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $result[] = $this->normalizeMap($item);
        }

        return $result;
    }

    /** @param array<string, mixed> $entry */
    private function isInterval(array $entry): bool
    {
        return array_key_exists('close', $entry) || array_key_exists('log', $entry);
    }

    /** @param array<string, mixed> $entry */
    private function isOrphan(array $entry): bool
    {
        return array_key_exists('openId', $entry);
    }

    /** @param list<array<string, mixed>> $entries */
    private function attachChildren(TreeNode $node, array $entries): void
    {
        foreach ($entries as $entry) {
            $node->addChild($this->isInterval($entry) ? $this->parseOpen($entry, $node) : $this->parseEvent($entry, $node));
        }
    }

    /** @param array<string, mixed> $entry */
    private function parseOpen(array $entry, TreeNode|null $parent): TreeNode
    {
        $context = $this->contextOf($entry);
        $node = new TreeNode(
            self::stringifyScalar($entry['id'] ?? null, 'unknown'),
            self::stringifyScalar($entry['type'] ?? null, 'unknown'),
            $context,
            $this->extractExecutionTime($context),
            $parent,
        );

        if (array_key_exists('log', $entry) && is_array($entry['log'])) {
            $this->attachChildren($node, $this->arrayItems($entry['log']));
        }

        if (array_key_exists('close', $entry) && is_array($entry['close'])) {
            /** @var array<string, mixed> $closeEntry */
            $closeEntry = $entry['close'];
            $node->setClose(
                self::stringifyScalar($closeEntry['type'] ?? null, 'unknown'),
                $this->contextOf($closeEntry),
                $this->profileOf($closeEntry),
            );
        }

        return $node;
    }

    /** @param array<string, mixed> $entry */
    private function parseEvent(array $entry, TreeNode $parent): TreeNode
    {
        $context = $this->contextOf($entry);
        $node = new TreeNode(
            self::stringifyScalar($entry['id'] ?? null, 'unknown'),
            self::stringifyScalar($entry['type'] ?? null, 'unknown'),
            $context,
            $this->extractExecutionTime($context),
            $parent,
        );
        $node->isEvent = true;

        return $node;
    }

    /** @param array<string, mixed> $closeEntry */
    private function createOrphanCloseNode(array $closeEntry, TreeNode $parent): TreeNode
    {
        $context = $this->contextOf($closeEntry);
        $node = new TreeNode(
            $this->orphanCloseId($closeEntry['id'] ?? null),
            self::stringifyScalar($closeEntry['type'] ?? null, 'unknown'),
            $context,
            $this->extractExecutionTime($context),
            $parent,
        );
        $node->isOrphanClose = true;
        $node->closeProfile = $this->profileOf($closeEntry);

        return $node;
    }

    private function orphanCloseId(mixed $id): string
    {
        if (is_scalar($id) && (string) $id !== '') {
            return (string) $id;
        }

        $this->orphanCloseSequence++;

        return 'orphan_close_' . (string) $this->orphanCloseSequence;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function contextOf(array $entry): array
    {
        /** @var mixed $context */
        $context = $entry['context'] ?? [];

        return is_array($context) ? $this->normalizeMap($context) : [];
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function profileOf(array $entry): array
    {
        /** @var mixed $profile */
        $profile = $entry['profile'] ?? [];

        return is_array($profile) ? $this->normalizeMap($profile) : [];
    }

    /** @param array<string, mixed> $context */
    private function extractExecutionTime(array $context): float
    {
        // Try different possible time fields
        $timeFields = [
            'executionTime',
            'responseTime',
            'duration',
            'processingTime',
            'connectionTime',
        ];

        foreach ($timeFields as $field) {
            if (array_key_exists($field, $context)) {
                /** @var mixed $value */
                $value = $context[$field];
                if (is_numeric($value)) {
                    return (float) $value;
                }
            }
        }

        return 0.0;
    }

    private static function stringifyScalar(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function normalizeMap(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($values));

        return array_combine($keys, array_values($values));
    }
}
