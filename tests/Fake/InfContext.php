<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

/**
 * A context carrying data json_encode() cannot represent (INF).
 * Models the data-dependent production failure: correct code, bad runtime data.
 */
final class InfContext extends AbstractContext
{
    public const TYPE = 'inf_context';
    public const SCHEMA_URL = 'https://example.com/schemas/inf.json';

    public function __construct(
        public readonly float $value = INF,
    ) {
    }
}
