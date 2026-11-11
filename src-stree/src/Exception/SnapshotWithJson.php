<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree\Exception;

use RuntimeException;

/**
 * `--snapshot` was given together with `--json`.
 *
 * `--json` prints the log as recorded, timings included; snapshot output applies to the tree only.
 */
final class SnapshotWithJson extends RuntimeException
{
}
