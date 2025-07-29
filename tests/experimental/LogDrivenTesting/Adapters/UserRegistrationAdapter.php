<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting\Adapters;

use Koriym\SemanticLogger\Experimental\LogDrivenTesting\RequestAdapterInterface;

/**
 * Adapter for user registration operations
 */
final class UserRegistrationAdapter implements RequestAdapterInterface
{
    public function canHandle(string $type): bool
    {
        return $type === 'user_registration';
    }

    /** @param array<string, mixed> $context */
    public function execute(array $context): mixed
    {
        // Simulate user registration logic
        $email = $context['email'] ?? '';

        if (empty($email)) {
            return ['error' => 'Email is required'];
        }

        // Simulate successful registration
        return [
            'user_id' => 123,
            'email' => $email,
            'status' => 'registered',
        ];
    }
}
