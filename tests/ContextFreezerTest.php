<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

final class ContextFreezerTest extends TestCase
{
    public function testFreezePreservesObjectResultFromJsonSerialize(): void
    {
        // JsonSerializable::jsonSerialize() may return an object (stdClass).
        // The recorded context must be the serialized form, not a cast of the
        // context object itself (which would expose the $fallback distractor).
        $frozen = (new ContextFreezer())->freeze(new ObjectSerializingContext(), 'open');

        $this->assertNull($frozen['diagnostic']);
        $this->assertSame(['serialized' => 'value'], $frozen['context']);
    }

    public function testFreezePreservesDtoResultFromJsonSerialize(): void
    {
        // The same guarantee holds when jsonSerialize() returns a DTO (any
        // object), not only stdClass: the serialized form is recorded, never
        // the context's own properties.
        $frozen = (new ContextFreezer())->freeze(new DtoSerializingContext(), 'event');

        $this->assertNull($frozen['diagnostic']);
        $this->assertSame(['serialized' => 'value'], $frozen['context']);
    }
}
