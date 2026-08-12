<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use SplStack;

use function array_reverse;
use function iterator_to_array;

/**
 * @psalm-import-type EventEntryList from Types
 * @psalm-import-type LogTree from Types
 * @psalm-import-type OpenChildrenByParent from Types
 * @psalm-import-type OpenCloseEntryList from Types
 */
final class LogTreeBuilder
{
    /**
     * @param OpenCloseEntryList $completedOperations
     * @param EventEntryList     $closeLog
     *
     * @return LogTree
     */
    public function completed(array $completedOperations, array $closeLog): array
    {
        return $this->buildTree($completedOperations, $closeLog);
    }

    /**
     * @param OpenCloseEntryList       $completedOperations
     * @param SplStack<OpenCloseEntry> $openStack
     * @param EventEntryList           $closeLog
     *
     * @return LogTree
     */
    public function includingLive(array $completedOperations, SplStack $openStack, array $closeLog): array
    {
        $operations = $completedOperations;
        /** @var list<OpenCloseEntry> $liveOperations */
        $liveOperations = array_reverse(iterator_to_array($openStack, false));
        foreach ($liveOperations as $operation) {
            $operations[] = $operation;
        }

        return $this->buildTree($operations, $closeLog);
    }

    /**
     * @param OpenCloseEntryList $operations
     * @param EventEntryList     $closeLog
     *
     * @return LogTree
     */
    private function buildTree(array $operations, array $closeLog): array
    {
        $childrenByParent = $this->groupByParent($operations);
        $closeByOpenId = [];
        foreach ($closeLog as $close) {
            if ($close->openId !== null) {
                $closeByOpenId[$close->openId] = $close;
            }
        }

        return [
            'open' => $this->buildOpenChildren(null, $childrenByParent),
            'close' => $this->buildCloseChildren(null, $childrenByParent, $closeByOpenId),
        ];
    }

    /**
     * @param OpenChildrenByParent $childrenByParent
     *
     * @return OpenCloseEntryList
     */
    private function buildOpenChildren(string|null $parentId, array $childrenByParent): array
    {
        $key = $parentId ?? '';
        if (! isset($childrenByParent[$key])) {
            return [];
        }

        $result = [];
        foreach ($childrenByParent[$key] as $operation) {
            $result[] = new OpenCloseEntry(
                $operation->id,
                $operation->type,
                $operation->schemaUrl,
                $operation->context,
                $this->buildOpenChildren($operation->id, $childrenByParent),
                $operation->parentId,
            );
        }

        return $result;
    }

    /**
     * @param OpenChildrenByParent      $childrenByParent
     * @param array<string, EventEntry> $closeByOpenId
     *
     * @return EventEntryList
     */
    private function buildCloseChildren(string|null $parentId, array $childrenByParent, array $closeByOpenId): array
    {
        $key = $parentId ?? '';
        if (! isset($childrenByParent[$key])) {
            return [];
        }

        $result = [];
        foreach ($childrenByParent[$key] as $operation) {
            $close = $closeByOpenId[$operation->id] ?? null;
            $childCloses = $this->buildCloseChildren($operation->id, $childrenByParent, $closeByOpenId);
            if ($close === null) {
                foreach ($childCloses as $childClose) {
                    $result[] = $childClose;
                }

                continue;
            }

            $result[] = new EventEntry(
                $close->id,
                $close->type,
                $close->schemaUrl,
                $close->context,
                $close->openId,
                $childCloses,
                $close->profile,
            );
        }

        return $result;
    }

    /**
     * @param OpenCloseEntryList $operations
     *
     * @return OpenChildrenByParent
     */
    private function groupByParent(array $operations): array
    {
        $childrenByParent = [];
        foreach ($operations as $operation) {
            $key = $operation->parentId ?? '';
            $childrenByParent[$key][] = $operation;
        }

        return $childrenByParent;
    }
}
