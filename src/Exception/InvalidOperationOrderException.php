<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Exception;

use function sprintf;

final class InvalidOperationOrderException extends LogicException
{
    public function __construct(
        public readonly string $providedId,
        public readonly string $expectedId,
    ) {
        parent::__construct(
            sprintf(
                "Cannot close operation '%s': expected '%s' (LIFO order required)",
                $providedId,
                $expectedId,
            ),
        );
    }
}