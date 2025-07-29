<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * Semantic Logger Interface for hierarchical structured logging
 *
 * Provides type-safe logging with JSON schema validation for complex application workflows.
 * Supports open/event/close patterns for tracking nested operations.
 */
interface SemanticLoggerInterface
{
    /**
     * Start a new hierarchical operation context
     *
     * Opens a new logging context that can contain nested operations and events.
     * Returns a type-based unique ID that must be used to close this operation.
     *
     * @return string Type-based operation ID (e.g., "user_registration_1") for closing this operation
     */
    public function open(AbstractContext $context): string;

    /**
     * Log an event within the current operation context
     *
     * Records events that occur during the execution of an open operation.
     * Events are associated with the currently active operation context.
     */
    public function event(AbstractContext $context): void;

    /**
     * End a specific operation with a result/status
     *
     * Closes the operation identified by the given ID with the provided context.
     * Must follow LIFO (Last-In-First-Out) order for nested operations.
     * The close context typically contains results, status, or completion data.
     *
     * @param string $openId The ID returned by the corresponding open() call (must match the most recent open operation)
     */
    public function close(AbstractContext $context, string $openId): void;

    /**
     * Get the complete log data and reset the logger state
     *
     * Returns the entire log session as a structured LogJson object and resets
     * the internal state for the next logging session. This implements the
     * flush pattern for one-time log consumption.
     *
     * @param list<array{rel: string, href: string, title?: string, type?: string}> $relations Optional relations for complete system transparency
     */
    public function flush(array $relations = []): LogJson;
}
