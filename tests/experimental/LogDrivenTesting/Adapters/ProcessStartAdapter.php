<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting\Adapters;

use Koriym\SemanticLogger\Experimental\LogDrivenTesting\RequestAdapterInterface;

use function is_int;
use function is_string;

/**
 * Adapter for process start operations
 */
final class ProcessStartAdapter implements RequestAdapterInterface
{
    public function canHandle(string $type): bool
    {
        return $type === 'process_start';
    }

    /** @param array<string, mixed> $context */
    public function execute(array $context): mixed
    {
        // Simulate process start logic
        $message = $context['message'] ?? '';
        $id = $context['id'] ?? 0;

        // Ensure types are correct
        $messageStr = is_string($message) ? $message : '';
        $idInt = is_int($id) ? $id : 0;

        // Simulate some processing
        return [
            'process_id' => $idInt,
            'status' => 'started',
            'message' => "Process started: $messageStr",
        ];
    }
}
