<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting;

/**
 * Interface for executing actual requests based on semantic log entries
 */
interface RequestAdapterInterface
{
    /**
     * Check if this adapter can handle the given request type
     */
    public function canHandle(string $type): bool;

    /**
     * Execute the actual request using the provided context
     *
     * @param array<string, mixed> $context Request context from the semantic log
     *
     * @return mixed The actual result from the request execution
     */
    public function execute(array $context): mixed;
}
