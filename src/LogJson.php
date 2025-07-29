<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use function array_map;

final class LogJson
{
    /**
     * @param list<EventEntry>                                                      $events
     * @param list<array{rel: string, href: string, title?: string, type?: string}> $relations
     */
    public function __construct(
        public readonly string $schemaUrl,
        public readonly OpenCloseEntry $open,
        public readonly EventEntry $close,
        /** @var list<EventEntry> */
        public readonly array $events = [],
        /** @var list<array{rel: string, href: string, title?: string, type?: string}> */
        public readonly array $relations = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            '$schema' => $this->schemaUrl,
            'open' => $this->open->toArray(),
        ];

        if (! empty($this->events)) {
            $result['events'] = array_map(static fn (EventEntry $event) => $event->toArray(), $this->events);
        }

        $result['close'] = $this->close->toArray();

        if (! empty($this->relations)) {
            $result['relations'] = $this->relations;
        }

        return $result;
    }
}
