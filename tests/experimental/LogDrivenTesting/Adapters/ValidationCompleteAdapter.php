<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting\Adapters;

use Koriym\SemanticLogger\Experimental\LogDrivenTesting\RequestAdapterInterface;

/**
 * Adapter for validation complete operations (handling validation responses)
 */
final class ValidationCompleteAdapter implements RequestAdapterInterface
{
    public function canHandle(string $type): bool
    {
        return $type === 'validation_complete';
    }

    /** @param array<string, mixed> $context */
    public function execute(array $context): mixed
    {
        // This adapter should not be called for requests
        // It's a response type, so we return what was expected
        return $context['result'] ?? [];
    }
}
