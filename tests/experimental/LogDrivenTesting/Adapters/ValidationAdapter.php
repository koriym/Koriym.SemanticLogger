<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting\Adapters;

use Koriym\SemanticLogger\Experimental\LogDrivenTesting\RequestAdapterInterface;

use function is_array;
use function is_string;

/**
 * Adapter for validation operations
 */
final class ValidationAdapter implements RequestAdapterInterface
{
    public function canHandle(string $type): bool
    {
        return $type === 'validation';
    }

    /** @param array<string, mixed> $context */
    public function execute(array $context): mixed
    {
        // Simulate validation logic
        $rules = $context['rules'] ?? [];
        $data = $context['data'] ?? [];

        $errors = [];

        if (! is_array($rules) || ! is_array($data)) {
            return ['valid' => false, 'errors' => ['Invalid input format']];
        }

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            if ($rule === 'email' && empty($data['email'])) {
                $errors[] = 'Email is required';
            }

            if ($rule === 'password' && empty($data['password'])) {
                $errors[] = 'Password is required';
            }
        }

        if (! empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        return ['valid' => true, 'validated_fields' => $rules];
    }
}
