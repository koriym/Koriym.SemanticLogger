<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;

use function array_map;

/**
 * @psalm-import-type CloseByOpenIdMap from Types
 * @psalm-import-type EventEntryList from Types
 * @psalm-import-type EventsByOpenIdMap from Types
 * @psalm-import-type LogSessionArray from Types
 * @psalm-import-type OpenCloseEntryList from Types
 * @psalm-import-type OperationIdSet from Types
 * @psalm-import-type PublicCloseEntry from Types
 * @psalm-import-type PublicEventEntry from Types
 * @psalm-import-type PublicOpenEntry from Types
 * @psalm-import-type SchemaLinks from Types
 */
final class LogJson implements JsonSerializable
{
    /**
     * @param OpenCloseEntryList      $open   Top-level opens (one or more) in chronological order.
     * @param EventEntryList          $close  Internal close entries; matched closes are nested during public serialization.
     * @param EventEntryList          $events
     * @param SchemaLinks             $links
     * @param SemanticLoggerMode|null $mode   The logger mode that produced this log. Recorded in the envelope so the
     *                                        document is self-describing: a total-mode document with no diagnostics
     *                                        is proof of a clean session, a mode-less document carries no such proof.
     */
    public function __construct(
        public readonly string $schemaUrl,
        public readonly array $open,
        public readonly array $close,
        public readonly array $events = [],
        public readonly array $links = [],
        public readonly SemanticLoggerMode|null $mode = null,
    ) {
    }

    /** @return LogSessionArray Public tree JSON representation */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Render this log with the given renderer (double dispatch).
     *
     * The log does not know any concrete output format. It hands itself back to
     * the renderer, which decides how to present it (tree, markdown, ...), so
     * output formats can be swapped without touching the log.
     */
    public function render(LogRendererInterface $renderer): string
    {
        return $renderer->render($this);
    }

    /** @return LogSessionArray */
    public function toArray(): array
    {
        $closeByOpenId = [];
        $openIds = [];
        $this->collectOpenIds($this->open, $openIds);
        $orphanCloses = [];
        $this->partitionCloses($this->close, $openIds, $closeByOpenId, $orphanCloses);
        $eventsByOpenId = $this->groupEventsByOpenId($this->events);

        $result = ['$schema' => $this->schemaUrl];

        if ($this->mode !== null) {
            $result['mode'] = $this->mode->value;
        }

        $result['open'] = array_map(
            fn (OpenCloseEntry $entry): array => $this->buildTreeOpenEntry($entry, $closeByOpenId, $eventsByOpenId),
            $this->open,
        );

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

    /** @return LogSessionArray */
    public function toTreeArray(): array
    {
        return $this->toArray();
    }

    /**
     * @param CloseByOpenIdMap  $closeByOpenId
     * @param EventsByOpenIdMap $eventsByOpenId
     *
     * @return PublicOpenEntry
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
     * @param EventEntryList            $closes
     * @param array<string, true>       $openIds
     * @param array<string, EventEntry> $closeByOpenId
     * @param list<EventEntry>          $orphanCloses
     */
    private function partitionCloses(array $closes, array $openIds, array &$closeByOpenId, array &$orphanCloses): void
    {
        foreach ($closes as $close) {
            $openId = $close->openId;
            // The first matching close owns the operation; later duplicates remain
            // visible as orphan diagnostics instead of silently replacing it.
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
     * @param EventEntryList $events
     *
     * @return EventsByOpenIdMap
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
     * @param OpenCloseEntryList  $entries
     * @param array<string, true> $openIds
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
     * @return EventEntryList
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

    /** @return PublicEventEntry */
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
