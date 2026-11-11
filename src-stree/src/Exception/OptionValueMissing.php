<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree\Exception;

/**
 * A CLI option that takes a value was the last argument.
 *
 * Message format: the option name
 */
final class OptionValueMissing extends RuntimeException
{
    public function __construct(public readonly string $option)
    {
        parent::__construct($option);
    }
}
