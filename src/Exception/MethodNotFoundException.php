<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Exception;

use Exception;

final class MethodNotFoundException extends Exception
{
    public function getJsonRpcCode(): int
    {
        return -32601;
    }
}
