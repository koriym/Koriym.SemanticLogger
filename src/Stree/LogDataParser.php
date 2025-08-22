<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use RuntimeException;

use function array_key_exists;
use function is_array;
use function is_numeric;

final class LogDataParser
{
    /** @param array<string, mixed> $logData */
    public function parseLogData(array $logData): TreeNode
    {
        if (! array_key_exists('open', $logData) || ! is_array($logData['open'])) {
            throw new RuntimeException('Invalid log data: missing open section');
        }

        /** @var array<string, mixed> $openData */
        $openData = $logData['open'];

        // Parse the hierarchical open structure
        $rootNode = $this->parseOpenEntry($openData);

        // Add events as leaf nodes
        if (array_key_exists('events', $logData) && is_array($logData['events'])) {
            /** @var array<string, mixed>[] $events */
            $events = $logData['events'];
            $this->attachEvents($rootNode, $events);
        }

        return $rootNode;
    }

    /** @param array<string, mixed> $openEntry */
    private function parseOpenEntry(array $openEntry, TreeNode|null $parent = null): TreeNode
    {
        $id = (string) ($openEntry['id'] ?? 'unknown');
        $type = (string) ($openEntry['type'] ?? 'unknown');
        $context = $openEntry['context'] ?? [];

        if (! is_array($context)) {
            $context = [];
        }

        // Extract execution time from context
        /** @var array<string, mixed> $context */
        $executionTime = $this->extractExecutionTime($context);

        $node = new TreeNode($id, $type, $context, $executionTime, $parent);

        // Check for nested open entries
        if (array_key_exists('open', $openEntry) && is_array($openEntry['open'])) {
            /** @var array<string, mixed> $nestedOpen */
            $nestedOpen = $openEntry['open'];
            $childNode = $this->parseOpenEntry($nestedOpen, $node);
            $node->addChild($childNode);
        }

        return $node;
    }

    /** @param array<string, mixed>[] $events */
    private function attachEvents(TreeNode $rootNode, array $events): void
    {
        foreach ($events as $event) {
            $eventId = (string) ($event['id'] ?? 'unknown');
            $eventType = (string) ($event['type'] ?? 'unknown');
            $eventContext = $event['context'] ?? [];
            $openId = isset($event['openId']) ? (string) $event['openId'] : null;

            if (! is_array($eventContext)) {
                $eventContext = [];
            }

            /** @var array<string, mixed> $eventContext */
            $executionTime = $this->extractExecutionTime($eventContext);

            $eventNode = new TreeNode($eventId, $eventType, $eventContext, $executionTime);

            // Find the parent node by openId
            $parentNode = $this->findNodeById($rootNode, $openId);
            if ($parentNode !== null) {
                $parentNode->addChild($eventNode);
            } else {
                // If no parent found, attach to root
                $rootNode->addChild($eventNode);
            }
        }
    }

    /** @codeCoverageIgnore */
    private function findNodeById(TreeNode $node, string|null $id): TreeNode|null
    {
        if ($id === null) {
            return null;
        }

        if ($node->id === $id) {
            return $node;
        }

        foreach ($node->children as $child) {
            $found = $this->findNodeById($child, $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
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
                $value = $context[$field];
                if (is_numeric($value)) {
                    return (float) $value;
                }
            }
        }

        return 0.0;
    }
}
