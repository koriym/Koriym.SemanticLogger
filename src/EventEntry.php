<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Koriym\SemanticLogger\Profiler\OperationProfile;
use Override;

use function array_map;

/**
 * @psalm-import-type ContextData from Types
 * @psalm-import-type EventEntryArray from Types
 */
final class EventEntry implements JsonSerializable
{
    /**
     * @param ContextData      $context
     * @param list<EventEntry> $close Child closes (zero or more) — immediate nested close entries in the order their opens closed.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $schemaUrl,
        public readonly array $context,
        public readonly string|null $openId = null,
        public readonly array $close = [],
        public readonly OperationProfile|null $profile = null,
    ) {
    }

    public function withProfile(OperationProfile $profile): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->schemaUrl,
            $this->context,
            $this->openId,
            $this->close,
            $profile,
        );
    }

    /** @param list<EventEntry> $close */
    public function withClose(array $close): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->schemaUrl,
            $this->context,
            $this->openId,
            $close,
            $this->profile,
        );
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

        if ($this->profile !== null) {
            $result['profile'] = $this->profile->jsonSerialize();
        }

        if ($this->close !== []) {
            $result['close'] = array_map(static fn (EventEntry $e) => $e->toArray(), $this->close);
        }

        return $result;
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
