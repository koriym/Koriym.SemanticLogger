<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;

/**
 * An event, as recorded. `openId` is the scope it was recorded in.
 *
 * @psalm-import-type ContextData from Types
 * @psalm-import-type EventEntryArray from Types
 */
final class EventEntry implements JsonSerializable
{
    /** @param ContextData $context */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $schemaUrl,
        public readonly array $context,
        public readonly string|null $openId = null,
    ) {
    }

    /** @return EventEntryArray */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'type' => $this->type,
            'schemaUrl' => $this->schemaUrl,
            'context' => $this->context,
        ];

        if ($this->openId !== null) {
            $result['openId'] = $this->openId;
        }

        return $result;
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
