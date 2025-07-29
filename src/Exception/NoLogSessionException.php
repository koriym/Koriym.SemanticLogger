<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Exception;

final class NoLogSessionException extends LogicException
{
    public function __construct(string $reason)
    {
        parent::__construct("Cannot create log session: {$reason}");
    }
}