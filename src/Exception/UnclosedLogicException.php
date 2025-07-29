<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Exception;

use LogicException;

use function sprintf;

final class UnclosedLogicException extends LogicException
{
    public function __construct(
        public readonly int $openStackDepth,
        public readonly string $lastOperationType,
        public readonly string $lastOperationSchema,
    ) {
        parent::__construct(
            sprintf(
                'Unclosed operations detected. %d operations remain open. Last operation: %s. See: https://github.com/koriym/semantic-logger/blob/main/docs/unclosed-operations.md',
                $openStackDepth,
                $lastOperationType,
            ),
        );
    }
}
