<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Turns the flat record list into the public tree: every open's events and
 * nested opens become one `children` list in record order, the first close
 * recorded for an open is nested on it, and every other close is an orphan.
 *
 * @psalm-import-type CloseByOpenIdMap from Types
 * @psalm-import-type EventEntryList from Types
 * @psalm-import-type LogRecordList from Types
 * @psalm-import-type OperationIdSet from Types
 * @psalm-import-type ParentByOpenId from Types
 * @psalm-import-type PublicCloseEntry from Types
 * @psalm-import-type PublicEntry from Types
 * @psalm-import-type PublicEventEntry from Types
 * @psalm-import-type PublicLogTree from Types
 * @psalm-import-type PublicOpenEntry from Types
 * @psalm-import-type RecordsByScope from Types
 */
final class LogTreeBuilder
{
    /**
     * @param LogRecordList  $records
     * @param EventEntryList $closes
     *
     * @return PublicLogTree
     */
    public function build(array $records, array $closes): array
    {
        $openIds = $this->collectOpenIds($records);
        $closeByOpenId = [];
        $orphanCloses = [];
        foreach ($closes as $close) {
            $openId = $close->openId;
            if ($openId !== null && isset($openIds[$openId]) && ! isset($closeByOpenId[$openId])) {
                $closeByOpenId[$openId] = $close;

                continue;
            }

            $orphanCloses[] = $this->closeToArray($close, true);
        }

        return [
            'entries' => $this->children('', $this->groupByScope($records, $openIds), $closeByOpenId),
            'close' => $orphanCloses,
        ];
    }

    /**
     * @param LogRecordList $records
     *
     * @return OperationIdSet
     */
    private function collectOpenIds(array $records): array
    {
        $openIds = [];
        foreach ($records as $record) {
            if ($record instanceof OpenCloseEntry) {
                $openIds[$record->id] = true;
            }
        }

        return $openIds;
    }

    /**
     * An open goes under its parent only when the parent chain reaches the
     * root; otherwise (unknown parent, self-parent, parent cycle) it goes to
     * the root. Every open is therefore reachable, so an event goes under its
     * scope whenever that scope is an open in this log.
     *
     * @param LogRecordList  $records
     * @param OperationIdSet $openIds
     *
     * @return RecordsByScope
     */
    private function groupByScope(array $records, array $openIds): array
    {
        $parentOf = $this->parentOf($records, $openIds);
        $recordsByScope = [];
        foreach ($records as $record) {
            $recordsByScope[$this->scopeKey($record, $openIds, $parentOf)][] = $record;
        }

        return $recordsByScope;
    }

    /**
     * @param OperationIdSet $openIds
     * @param ParentByOpenId $parentOf
     */
    private function scopeKey(OpenCloseEntry|EventEntry $record, array $openIds, array $parentOf): string
    {
        if ($record instanceof OpenCloseEntry) {
            $parentId = $parentOf[$record->id] ?? null;

            return $parentId !== null && $this->reachesRoot($parentId, $parentOf) ? $parentId : '';
        }

        $openId = $record->openId;

        return $openId !== null && isset($openIds[$openId]) ? $openId : '';
    }

    /**
     * @param LogRecordList  $records
     * @param OperationIdSet $openIds
     *
     * @return ParentByOpenId Only parents that are opens in this log, never the open itself.
     */
    private function parentOf(array $records, array $openIds): array
    {
        $parentOf = [];
        foreach ($records as $record) {
            if (! $record instanceof OpenCloseEntry) {
                continue;
            }

            $parentId = $record->parentId;
            if ($parentId === null || $parentId === $record->id || ! isset($openIds[$parentId])) {
                continue;
            }

            $parentOf[$record->id] = $parentId;
        }

        return $parentOf;
    }

    /** @param ParentByOpenId $parentOf */
    private function reachesRoot(string $id, array $parentOf): bool
    {
        $seen = [];
        while (isset($parentOf[$id])) {
            if (isset($seen[$id])) {
                return false;
            }

            $seen[$id] = true;
            $id = $parentOf[$id];
        }

        return true;
    }

    /**
     * @param RecordsByScope   $recordsByScope
     * @param CloseByOpenIdMap $closeByOpenId
     *
     * @return list<PublicEntry>
     */
    private function children(string $scopeKey, array $recordsByScope, array $closeByOpenId): array
    {
        $children = [];
        foreach ($recordsByScope[$scopeKey] ?? [] as $record) {
            $children[] = $record instanceof OpenCloseEntry
                ? $this->openToArray($record, $recordsByScope, $closeByOpenId)
                : $this->eventToArray($record);
        }

        return $children;
    }

    /**
     * @param RecordsByScope   $recordsByScope
     * @param CloseByOpenIdMap $closeByOpenId
     *
     * @return PublicOpenEntry
     */
    private function openToArray(OpenCloseEntry $open, array $recordsByScope, array $closeByOpenId): array
    {
        $result = [
            'kind' => 'open',
            'id' => $open->id,
            'type' => $open->type,
            'schemaUrl' => $open->schemaUrl,
            'context' => $open->context,
            'children' => $this->children($open->id, $recordsByScope, $closeByOpenId),
        ];

        if (isset($closeByOpenId[$open->id])) {
            $result['close'] = $this->closeToArray($closeByOpenId[$open->id], false);
        }

        return $result;
    }

    /** @return PublicEventEntry */
    private function eventToArray(EventEntry $event): array
    {
        return [
            'kind' => 'event',
            'id' => $event->id,
            'type' => $event->type,
            'schemaUrl' => $event->schemaUrl,
            'context' => $event->context,
        ];
    }

    /** @return PublicCloseEntry */
    private function closeToArray(EventEntry $close, bool $includeOpenId): array
    {
        $result = [
            'id' => $close->id,
            'type' => $close->type,
            'schemaUrl' => $close->schemaUrl,
            'context' => $close->context,
        ];

        if ($includeOpenId && $close->openId !== null) {
            $result['openId'] = $close->openId;
        }

        if ($close->profile !== null) {
            $result['profile'] = $close->profile->jsonSerialize();
        }

        return $result;
    }
}
