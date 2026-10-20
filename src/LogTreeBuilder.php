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
     * A record whose scope is not an open in this log is placed at the root.
     *
     * @param LogRecordList  $records
     * @param OperationIdSet $openIds
     *
     * @return RecordsByScope
     */
    private function groupByScope(array $records, array $openIds): array
    {
        $recordsByScope = [];
        foreach ($records as $record) {
            $scopeId = $record instanceof OpenCloseEntry ? $record->parentId : $record->openId;
            $key = $scopeId !== null && isset($openIds[$scopeId]) ? $scopeId : '';
            $recordsByScope[$key][] = $record;
        }

        return $recordsByScope;
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
