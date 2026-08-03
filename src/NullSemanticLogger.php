<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Override;

/**
 * No-op semantic logger
 *
 * A minimal {@see SemanticLoggerInterface} for when logging is turned off.
 * Calls remain unconditional and the open/close id protocol stays intact, while
 * event() and close() do not retain log data.
 *
 * @psalm-import-type SchemaLinks from Types
 */
final class NullSemanticLogger implements SemanticLoggerInterface
{
    private const SEMANTIC_LOG_SCHEMA_URL = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';

    private int $sequence = 0;

    #[Override]
    public function open(AbstractContext $context): string
    {
        $this->sequence++;

        return 'noop_' . (string) $this->sequence;
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
    }

    /** @param SchemaLinks $links */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        $this->sequence = 0;

        return new LogJson(self::SEMANTIC_LOG_SCHEMA_URL, [], [], [], $links);
    }
}
