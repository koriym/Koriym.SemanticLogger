<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Turns the record list into the public `log`. Every open's events and
 * nested opens become its own `log` list in record order; the first close
 * recorded for an open becomes its `close`; every other close is a root
 * orphan, spliced into the root `log` right after the root record that was
 * being built when it was encountered.
 *
 * @psalm-import-type CloseByOpenIdMap from Types
 * @psalm-import-type LogRecordList from Types
 * @psalm-import-type OperationId from Types
 * @psalm-import-type OperationIdSet from Types
 * @psalm-import-type ParentByOpenId from Types
 * @psalm-import-type PublicClose from Types
 * @psalm-import-type PublicRecord from Types
 * @psalm-import-type RecordsByScope from Types
 * @psalm-import-type ScopedRecordList from Types
 */
final class LogTreeBuilder
{
    /**
     * @param LogRecordList $records
     *
     * @return list<PublicRecord>
     */
    public function build(array $records): array
    {
        $openIds = $this->collectOpenIds($records);
        $scoped = $this->scopedOnly($records);
        $parentOf = $this->parentOf($scoped, $openIds);
        $recordsByScope = $this->groupByScope($scoped, $openIds, $parentOf);
        [$closeByOpenId, $orphans] = $this->classifyCloses($records, $openIds, $parentOf);

        $rootList = $this->entriesIn('', $recordsByScope, $closeByOpenId);

        return $this->spliceOrphans($rootList, $orphans);
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
            if ($record instanceof OpenEntry) {
                $openIds[$record->id] = true;
            }
        }

        return $openIds;
    }

    /**
     * @param LogRecordList $records
     *
     * @return ScopedRecordList
     */
    private function scopedOnly(array $records): array
    {
        $scoped = [];
        foreach ($records as $record) {
            if (! $record instanceof CloseEntry) {
                $scoped[] = $record;
            }
        }

        return $scoped;
    }

    /**
     * An open goes under its parent only when the parent chain reaches the
     * root; otherwise (unknown parent, self-parent, parent cycle) it goes to
     * the root. Every open is therefore reachable, so an event goes under its
     * scope whenever that scope is an open in this log.
     *
     * @param ScopedRecordList $records
     * @param OperationIdSet   $openIds
     * @param ParentByOpenId   $parentOf
     *
     * @return RecordsByScope
     */
    private function groupByScope(array $records, array $openIds, array $parentOf): array
    {
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
    private function scopeKey(OpenEntry|EventEntry $record, array $openIds, array $parentOf): string
    {
        if ($record instanceof OpenEntry) {
            $parentId = $parentOf[$record->id] ?? null;

            return $parentId !== null && $this->reachesRoot($parentId, $parentOf) ? $parentId : '';
        }

        $openId = $record->openId;

        return $openId !== null && isset($openIds[$openId]) ? $openId : '';
    }

    /**
     * @param ScopedRecordList $records
     * @param OperationIdSet   $openIds
     *
     * @return ParentByOpenId Only parents that are opens in this log, never the open itself.
     */
    private function parentOf(array $records, array $openIds): array
    {
        $parentOf = [];
        foreach ($records as $record) {
            if (! $record instanceof OpenEntry) {
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
     * One pass over the full record order: matches each close to the first
     * unmatched open it names (a later close for the same open, or a close
     * naming no open in this log, is an orphan), and records the id of the
     * most recently started root-scope record at the moment each orphan was
     * encountered — the anchor it is spliced after in the root list.
     *
     * @param LogRecordList  $records
     * @param OperationIdSet $openIds
     * @param ParentByOpenId $parentOf
     *
     * @return array{0: CloseByOpenIdMap, 1: list<array{anchor: OperationId|null, close: CloseEntry}>}
     */
    private function classifyCloses(array $records, array $openIds, array $parentOf): array
    {
        $closeByOpenId = [];
        $orphans = [];
        $rootAnchor = null;
        foreach ($records as $record) {
            if ($record instanceof CloseEntry) {
                if (isset($openIds[$record->openId]) && ! isset($closeByOpenId[$record->openId])) {
                    $closeByOpenId[$record->openId] = $record;

                    continue;
                }

                $orphans[] = ['anchor' => $rootAnchor, 'close' => $record];

                continue;
            }

            if ($this->scopeKey($record, $openIds, $parentOf) === '') {
                $rootAnchor = $record->id;
            }
        }

        return [$closeByOpenId, $orphans];
    }

    /**
     * @param RecordsByScope   $recordsByScope
     * @param CloseByOpenIdMap $closeByOpenId
     *
     * @return list<PublicRecord>
     */
    private function entriesIn(string $scopeKey, array $recordsByScope, array $closeByOpenId): array
    {
        $entries = [];
        foreach ($recordsByScope[$scopeKey] ?? [] as $record) {
            $entries[] = $record instanceof OpenEntry
                ? $this->openToArray($record, $recordsByScope, $closeByOpenId)
                : $this->eventToArray($record);
        }

        return $entries;
    }

    /**
     * @param RecordsByScope   $recordsByScope
     * @param CloseByOpenIdMap $closeByOpenId
     *
     * @return PublicRecord
     */
    private function openToArray(OpenEntry $open, array $recordsByScope, array $closeByOpenId): array
    {
        $log = $this->entriesIn($open->id, $recordsByScope, $closeByOpenId);
        $close = $closeByOpenId[$open->id] ?? null;

        $result = [
            'id' => $open->id,
            'type' => $open->type,
            'schemaUrl' => $open->schemaUrl,
            'context' => $open->context,
        ];

        // `log` is present whenever the interval is unclosed (even empty);
        // omitted only when closed with no child records.
        if ($close === null || $log !== []) {
            $result['log'] = $log;
        }

        if ($close !== null) {
            $result['close'] = $this->closeToArray($close);
        }

        return $result;
    }

    /** @return PublicRecord */
    private function eventToArray(EventEntry $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'schemaUrl' => $event->schemaUrl,
            'context' => $event->context,
        ];
    }

    /** @return PublicClose */
    private function closeToArray(CloseEntry $close): array
    {
        $result = [
            'id' => $close->id,
            'type' => $close->type,
            'schemaUrl' => $close->schemaUrl,
            'context' => $close->context,
        ];

        if ($close->profile !== null) {
            $result['profile'] = $close->profile->jsonSerialize();
        }

        return $result;
    }

    /** @return PublicRecord */
    private function orphanToArray(CloseEntry $close): array
    {
        $result = [
            'id' => $close->id,
            'type' => $close->type,
            'schemaUrl' => $close->schemaUrl,
            'context' => $close->context,
            'openId' => $close->openId,
        ];

        if ($close->profile !== null) {
            $result['profile'] = $close->profile->jsonSerialize();
        }

        return $result;
    }

    /**
     * @param list<PublicRecord>                                       $rootList
     * @param list<array{anchor: OperationId|null, close: CloseEntry}> $orphans
     *
     * @return list<PublicRecord>
     */
    private function spliceOrphans(array $rootList, array $orphans): array
    {
        if ($orphans === []) {
            return $rootList;
        }

        $indexOf = [];
        foreach ($rootList as $index => $record) {
            $indexOf[$record['id']] ??= $index;
        }

        $afterIndex = [];
        foreach ($orphans as $orphan) {
            $anchor = $orphan['anchor'];
            $position = $anchor !== null && isset($indexOf[$anchor]) ? $indexOf[$anchor] : -1;
            $afterIndex[$position][] = $this->orphanToArray($orphan['close']);
        }

        $result = $afterIndex[-1] ?? [];
        foreach ($rootList as $index => $record) {
            $result[] = $record;
            foreach ($afterIndex[$index] ?? [] as $orphanRecord) {
                $result[] = $orphanRecord;
            }
        }

        return $result;
    }
}
