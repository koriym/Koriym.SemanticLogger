<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
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
    ) {
    }

    /** @return array{id: string, type: string, '$schema': string, context: array<string, mixed>, openId?: string, close?: array<string, mixed>} */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'type' => $this->type,
            '$schema' => $this->schemaUrl,
            'context' => $this->context,
        ];

        if ($this->openId !== null) {
            $result['openId'] = $this->openId;
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
