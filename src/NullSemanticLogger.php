<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Override;

/**
 * No-op semantic logger
 *
 * A zero-cost {@see SemanticLoggerInterface} for when logging is turned off:
 * open() returns an empty id (callers treat it as "no close needed"), event()
 * and close() do nothing, and flush() returns an empty log session. Useful as a
 * default so instrumentation code can call the logger unconditionally without a
 * runtime cost when observability is disabled.
 *
 * @psalm-import-type SchemaLinks from Types
 */
final class NullSemanticLogger implements SemanticLoggerInterface
{
    private const SEMANTIC_LOG_SCHEMA_URL = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';

    #[Override]
    public function open(AbstractContext $context): string
    {
        return '';
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
        return new LogJson(self::SEMANTIC_LOG_SCHEMA_URL, [], [], [], $links);
    }
}
