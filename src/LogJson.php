<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;

use function array_map;

final class LogJson implements JsonSerializable
{
    /**
     * @param list<OpenCloseEntry>                                                  $open   Top-level opens (one or more) in chronological order.
     * @param list<EventEntry>                                                      $close  Internal close entries; matched closes are nested during public serialization.
     * @param list<EventEntry>                                                      $events
     * @param list<array{rel: string, href: string, title?: string, type?: string}> $links
     */
    public function __construct(
        public readonly string $schemaUrl,
        public readonly array $open,
        public readonly array $close,
        public readonly array $events = [],
        public readonly array $links = [],
    ) {
    }

    /** @return array<string, mixed> Public tree JSON representation */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $closeByOpenId = [];
        $openIds = [];
        $this->collectOpenIds($this->open, $openIds);
        $orphanCloses = [];
        $this->partitionCloses($this->close, $openIds, $closeByOpenId, $orphanCloses);
        $eventsByOpenId = $this->groupEventsByOpenId($this->events);

        $result = [
            '$schema' => $this->schemaUrl,
            'open' => array_map(
                fn (OpenCloseEntry $entry): array => $this->buildTreeOpenEntry($entry, $closeByOpenId, $eventsByOpenId),
                $this->open,
            ),
        ];

        $topLevelEvents = $this->topLevelTreeEvents($openIds);
        if ($topLevelEvents !== []) {
            $result['events'] = array_map(
                fn (EventEntry $event): array => $this->eventToArray($event, true),
                $topLevelEvents,
            );
        }

        if ($orphanCloses !== []) {
            $result['close'] = array_map(
                fn (EventEntry $close): array => $this->closeToArray($close, true),
                $orphanCloses,
            );
        }

        if (! empty($this->links)) {
            $result['links'] = $this->links;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function toTreeArray(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, EventEntry>       $closeByOpenId
     * @param array<string, list<EventEntry>> $eventsByOpenId
     *
     * @return array<string, mixed>
     */
    private function buildTreeOpenEntry(OpenCloseEntry $entry, array $closeByOpenId, array $eventsByOpenId): array
    {
        $result = $entry->toArray();

        $events = $eventsByOpenId[$entry->id] ?? [];
        if ($events !== []) {
            $result['events'] = array_map(
                fn (EventEntry $event): array => $this->eventToArray($event, false),
                $events,
            );
        }

        if (isset($closeByOpenId[$entry->id])) {
            $result['close'] = $this->closeToArray($closeByOpenId[$entry->id], false);
        }

        if ($entry->open === []) {
            unset($result['open']);

            return $result;
        }

        $result['open'] = array_map(
            fn (OpenCloseEntry $child): array => $this->buildTreeOpenEntry($child, $closeByOpenId, $eventsByOpenId),
            $entry->open,
        );

        return $result;
    }

    /**
     * @param list<EventEntry>          $closes
     * @param array<string, true>       $openIds
     * @param array<string, EventEntry> $closeByOpenId
     * @param list<EventEntry>          $orphanCloses
     */
    private function partitionCloses(array $closes, array $openIds, array &$closeByOpenId, array &$orphanCloses): void
    {
        foreach ($closes as $close) {
            $openId = $close->openId;
            $isMatchedClose = $openId !== null && isset($openIds[$openId]) && ! isset($closeByOpenId[$openId]);
            if ($isMatchedClose) {
                $closeByOpenId[$openId] = $close;
            }

            if (! $isMatchedClose) {
                $orphanCloses[] = $close;
            }

            if ($close->close !== []) {
                $this->partitionCloses($close->close, $openIds, $closeByOpenId, $orphanCloses);
            }
        }
    }

    /**
     * @param list<EventEntry> $events
     *
     * @return array<string, list<EventEntry>>
     */
    private function groupEventsByOpenId(array $events): array
    {
        $eventsByOpenId = [];
        foreach ($events as $event) {
            $eventsByOpenId[$event->openId ?? ''][] = $event;
        }

        return $eventsByOpenId;
    }

    /**
     * @param list<OpenCloseEntry> $entries
     * @param array<string, true>  $openIds
     */
    private function collectOpenIds(array $entries, array &$openIds): void
    {
        foreach ($entries as $entry) {
            $openIds[$entry->id] = true;

            if ($entry->open !== []) {
                $this->collectOpenIds($entry->open, $openIds);
            }
        }
    }

    /**
     * @param array<string, true> $openIds
     *
     * @return list<EventEntry>
     */
    private function topLevelTreeEvents(array $openIds): array
    {
        $topLevelEvents = [];
        foreach ($this->events as $event) {
            $openId = $event->openId;
            if ($openId === null || ! isset($openIds[$openId])) {
                $topLevelEvents[] = $event;
            }
        }

        return $topLevelEvents;
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private function eventToArray(EventEntry $event, bool $includeOpenId): array
    {
        $result = [
            'id' => $event->id,
            'type' => $event->type,
            'schemaUrl' => $event->schemaUrl,
            'context' => $event->context,
        ];

        if ($includeOpenId && $event->openId !== null) {
            $result['openId'] = $event->openId;
        }

        return $result;
    }
}
