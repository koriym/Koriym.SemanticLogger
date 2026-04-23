<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;

use function array_map;

/**
 * @psalm-import-type ContextData from Types
 * @psalm-import-type OpenCloseEntryArray from Types
 */
final class OpenCloseEntry implements JsonSerializable
{
    /**
     * @param ContextData          $context
     * @param list<OpenCloseEntry> $open Child opens (zero or more) — immediate nested operations in chronological order.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $schemaUrl,
        public readonly array $context,
        public readonly array $open = [],
        public readonly string|null $parentId = null,
    ) {
    }

    /** @return OpenCloseEntryArray */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'type' => $this->type,
            'schemaUrl' => $this->schemaUrl,
            'context' => $this->context,
        ];

        if ($this->open !== []) {
            $result['open'] = array_map(static fn (OpenCloseEntry $e) => $e->toArray(), $this->open);
        }

        return $result;
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
