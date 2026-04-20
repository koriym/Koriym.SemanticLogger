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
}
