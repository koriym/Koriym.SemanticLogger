<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Koriym\SemanticLogger\Profiler\OperationProfile;
use Override;

final class EventEntry implements JsonSerializable
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $schemaUrl,
        public readonly array $context,
        public readonly string|null $openId = null,
        public readonly EventEntry|null $close = null,
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

    public function withClose(EventEntry|null $close): self
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

    /** @return array<string, mixed> */
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

        if ($this->close !== null) {
            $result['close'] = $this->close->toArray();
        }

        return $result;
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
