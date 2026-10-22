<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;

/**
 * An open, as recorded. `parentId` is the open that was innermost at the time.
 *
 * @psalm-import-type ContextData from Types
 * @psalm-import-type OpenEntryArray from Types
 */
final class OpenEntry implements JsonSerializable
{
    /** @param ContextData $context */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $schemaUrl,
        public readonly array $context,
        public readonly string|null $parentId = null,
    ) {
    }

    /** @return OpenEntryArray */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'type' => $this->type,
            'schemaUrl' => $this->schemaUrl,
            'context' => $this->context,
        ];

        if ($this->parentId !== null) {
            $result['parentId'] = $this->parentId;
        }

        return $result;
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
