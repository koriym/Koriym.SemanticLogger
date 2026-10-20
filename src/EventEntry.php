<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Koriym\SemanticLogger\Profiler\OperationProfile;
use Override;

/**
 * An event or a close, as recorded. `openId` is the scope the event was
 * recorded in, or the open this close ended.
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
            $profile,
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

        return $result;
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
