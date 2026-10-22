<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Koriym\SemanticLogger\Profiler\OperationProfile;
use Override;

/**
 * A close, as recorded. `openId` is the open this close ended.
 *
 * @psalm-import-type CloseEntryArray from Types
 * @psalm-import-type ContextData from Types
 */
final class CloseEntry implements JsonSerializable
{
    /** @param ContextData $context */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $schemaUrl,
        public readonly array $context,
        public readonly string $openId,
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

    /** @return CloseEntryArray */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'type' => $this->type,
            'schemaUrl' => $this->schemaUrl,
            'context' => $this->context,
            'openId' => $this->openId,
        ];

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
