<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Exception;

final class NoOpenOperationsException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Cannot close operation: no open operations');
    }
}