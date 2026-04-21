<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;

use function array_map;
use function is_array;

final class LogJson implements JsonSerializable
{
    /**
     * @param list<OpenCloseEntry>                                                  $open   Top-level opens (one or more) in chronological order.
     * @param list<EventEntry>                                                      $close  Top-level closes (parallel to $open).
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

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            '$schema' => $this->schemaUrl,
            'open' => array_map(static fn (OpenCloseEntry $e) => $e->toArray(), $this->open),
        ];

        if (! empty($this->events)) {
            $result['events'] = array_map(static fn (EventEntry $event) => $event->toArray(), $this->events);
        }

        $result['close'] = array_map(static fn (EventEntry $e) => $e->toArray(), $this->close);

        if (! empty($this->links)) {
            $result['links'] = $this->links;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function toTreeArray(): array
    {
        $closeByOpenId = [];
        $this->indexCloses($this->close, $closeByOpenId);
        $eventsByOpenId = $this->groupEventsByOpenId($this->events);

        $result = [
            '$schema' => $this->schemaUrl,
            'open' => array_map(
                fn (OpenCloseEntry $entry): array => $this->buildTreeOpenEntry($entry, $closeByOpenId, $eventsByOpenId),
                $this->open,
            ),
        ];

        if (isset($eventsByOpenId['']) && $eventsByOpenId[''] !== []) {
            $result['events'] = array_map(static fn (EventEntry $event): array => $event->toArray(), $eventsByOpenId['']);
        }

        if (! empty($this->links)) {
            $result['links'] = $this->links;
        }

        return $result;
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
            $result['events'] = array_map(static fn (EventEntry $event): array => $event->toArray(), $events);
        }

        if (isset($closeByOpenId[$entry->id])) {
            $result['close'] = $this->singleCloseToArray($closeByOpenId[$entry->id]);
        }

        if (! isset($result['open']) || ! is_array($result['open'])) {
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
     * @param array<string, EventEntry> $closeByOpenId
     */
    private function indexCloses(array $closes, array &$closeByOpenId): void
    {
        foreach ($closes as $close) {
            if ($close->openId !== null) {
                $closeByOpenId[$close->openId] = $close;
            }

            if ($close->close !== []) {
                $this->indexCloses($close->close, $closeByOpenId);
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

    /** @return array<string, mixed> */
    private function singleCloseToArray(EventEntry $close): array
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
}
