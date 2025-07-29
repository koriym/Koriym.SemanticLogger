<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

final class OpenCloseEntry
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $schemaUrl,
        public readonly array $context,
        public readonly OpenCloseEntry|null $open = null,
    ) {
    }

    /** @return array{id: string, type: string, '$schema': string, context: array<string, mixed>, open?: array<string, mixed>} */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'type' => $this->type,
            '$schema' => $this->schemaUrl,
            'context' => $this->context,
        ];

        if ($this->open !== null) {
            $result['open'] = $this->open->toArray();
        }

        return $result;
    }
}
