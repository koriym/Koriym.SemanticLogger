<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use RuntimeException;

use function array_key_exists;
use function count;
use function is_array;
use function is_numeric;
use function is_scalar;

final class LogDataParser
{
    /** @param array<string, mixed> $logData */
    public function parseLogData(array $logData): TreeNode
    {
        if (! array_key_exists('open', $logData) || ! is_array($logData['open'])) {
            throw new RuntimeException('Invalid log data: missing open section');
        }

        /** @var list<array<string, mixed>> $openList */
        $openList = $logData['open'];
        if ($openList === []) {
            throw new RuntimeException('Invalid log data: open section is empty');
        }

        if (count($openList) !== 1) {
            throw new RuntimeException('stree rendering expects a single root open entry; wrap sibling roots in a parent span.');
        }

        $rootNode = $this->parseOpenEntry($openList[0]);

        // Merge close entries into their matching open nodes by openId.
        if (array_key_exists('close', $logData) && is_array($logData['close'])) {
            /** @var list<array<string, mixed>> $closeList */
            $closeList = $logData['close'];
            foreach ($closeList as $closeEntry) {
                $this->attachSingleClose($rootNode, $closeEntry);
            }
        }

        // Add events as leaf nodes
        if (array_key_exists('events', $logData) && is_array($logData['events'])) {
            /** @var array<string, mixed>[] $events */
            $events = $logData['events'];
            $this->attachEvents($rootNode, $events);
        }

        return $rootNode;
    }

    /** @param array<string, mixed> $closeEntry */
    private function attachSingleClose(TreeNode $rootNode, array $closeEntry): void
    {
        /** @var mixed $rawOpenId */
        $rawOpenId = $closeEntry['openId'] ?? null;
        $openId = is_scalar($rawOpenId) ? (string) $rawOpenId : null;
        $type = self::stringifyScalar($closeEntry['type'] ?? null, 'unknown');
        /** @var mixed $context */
        $context = $closeEntry['context'] ?? [];
        /** @var mixed $profile */
        $profile = $closeEntry['profile'] ?? [];
        if (! is_array($context)) {
            $context = [];
        }

        if (! is_array($profile)) {
            $profile = [];
        }

        $node = $openId !== null ? $this->findNodeById($rootNode, $openId) : null;
        if ($node !== null) {
            /** @var array<string, mixed> $closeCtx */
            $closeCtx = $context;
            /** @var array<string, mixed> $closeProfile */
            $closeProfile = $profile;
            $node->setClose($type, $closeCtx, $closeProfile);
        }

        if (array_key_exists('close', $closeEntry) && is_array($closeEntry['close'])) {
            /** @var list<array<string, mixed>> $nested */
            $nested = $closeEntry['close'];
            foreach ($nested as $child) {
                $this->attachSingleClose($rootNode, $child);
            }
        }
    }

    /** @param array<string, mixed> $openEntry */
    private function parseOpenEntry(array $openEntry, TreeNode|null $parent = null): TreeNode
    {
        $id = self::stringifyScalar($openEntry['id'] ?? null, 'unknown');
        $type = self::stringifyScalar($openEntry['type'] ?? null, 'unknown');
        /** @var mixed $context */
        $context = $openEntry['context'] ?? [];

        if (! is_array($context)) {
            $context = [];
        }

        // Extract execution time from context
        /** @var array<string, mixed> $context */
        $executionTime = $this->extractExecutionTime($context);

        $node = new TreeNode($id, $type, $context, $executionTime, $parent);

        if (array_key_exists('close', $openEntry) && is_array($openEntry['close'])) {
            /** @var array<string, mixed> $closeEntry */
            $closeEntry = $openEntry['close'];
            $this->attachNestedCloseToNode($node, $closeEntry);
        }

        if (array_key_exists('events', $openEntry) && is_array($openEntry['events'])) {
            /** @var list<array<string, mixed>> $events */
            $events = $openEntry['events'];
            $this->attachNestedEventsToNode($node, $events);
        }

        // Walk nested sibling children (list shape).
        if (array_key_exists('open', $openEntry) && is_array($openEntry['open'])) {
            /** @var list<array<string, mixed>> $children */
            $children = $openEntry['open'];
            foreach ($children as $childEntry) {
                $node->addChild($this->parseOpenEntry($childEntry, $node));
            }
        }

        return $node;
    }

    /** @param array<string, mixed> $closeEntry */
    private function attachNestedCloseToNode(TreeNode $node, array $closeEntry): void
    {
        $type = self::stringifyScalar($closeEntry['type'] ?? null, 'unknown');
        /** @var mixed $rawContext */
        $rawContext = $closeEntry['context'] ?? [];
        /** @var mixed $rawProfile */
        $rawProfile = $closeEntry['profile'] ?? [];
        if (! is_array($rawContext)) {
            $rawContext = [];
        }

        if (! is_array($rawProfile)) {
            $rawProfile = [];
        }

        /** @var array<string, mixed> $context */
        $context = $rawContext;
        /** @var array<string, mixed> $profile */
        $profile = $rawProfile;
        $node->setClose($type, $context, $profile);
    }

    /** @param list<array<string, mixed>> $events */
    private function attachNestedEventsToNode(TreeNode $node, array $events): void
    {
        foreach ($events as $event) {
            $eventId = self::stringifyScalar($event['id'] ?? null, 'unknown');
            $eventType = self::stringifyScalar($event['type'] ?? null, 'unknown');
            /** @var mixed $eventContext */
            $eventContext = $event['context'] ?? [];
            if (! is_array($eventContext)) {
                $eventContext = [];
            }

            /** @var array<string, mixed> $eventContext */
            $executionTime = $this->extractExecutionTime($eventContext);
            $eventNode = new TreeNode($eventId, $eventType, $eventContext, $executionTime, $node);
            $eventNode->isEvent = true;
            $node->addChild($eventNode);
        }
    }

    /** @param array<string, mixed>[] $events */
    private function attachEvents(TreeNode $rootNode, array $events): void
    {
        foreach ($events as $event) {
            $eventId = self::stringifyScalar($event['id'] ?? null, 'unknown');
            $eventType = self::stringifyScalar($event['type'] ?? null, 'unknown');
            /** @var mixed $eventContext */
            $eventContext = $event['context'] ?? [];
            /** @var mixed $rawOpenId */
            $rawOpenId = $event['openId'] ?? null;
            $openId = is_scalar($rawOpenId) ? (string) $rawOpenId : null;

            if (! is_array($eventContext)) {
                $eventContext = [];
            }

            /** @var array<string, mixed> $eventContext */
            $executionTime = $this->extractExecutionTime($eventContext);

            $eventNode = new TreeNode($eventId, $eventType, $eventContext, $executionTime);
            $eventNode->isEvent = true;

            // Find the parent node by openId
            $parentNode = $this->findNodeById($rootNode, $openId);
            if ($parentNode !== null) {
                $parentNode->addChild($eventNode);

                continue;
            }

            // If no parent found, attach to root
            $rootNode->addChild($eventNode);
        }
    }

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
}
