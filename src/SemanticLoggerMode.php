<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

enum SemanticLoggerMode: string
{
    case Strict = 'strict';
    case Total = 'total';
}
