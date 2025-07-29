<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting;

use RuntimeException;

/**
 * Registry for managing request adapters
 */
final class AdapterRegistry
{
    /** @var RequestAdapterInterface[] */
    private array $adapters = [];

    public function register(RequestAdapterInterface $adapter): void
    {
        $this->adapters[] = $adapter;
    }

    public function findAdapter(string $type): RequestAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->canHandle($type)) {
                return $adapter;
            }
        }

        throw new RuntimeException("No adapter found for request type: $type");
    }
}
