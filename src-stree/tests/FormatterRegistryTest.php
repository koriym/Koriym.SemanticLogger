<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use Koriym\SemanticLogger\Stree\Fake\FakeFormatter;
use PHPUnit\Framework\TestCase;

final class FormatterRegistryTest extends TestCase
{
    public function testGetReturnsNullForUnregisteredType(): void
    {
        $registry = new FormatterRegistry();

        $this->assertNull($registry->get('unknown_type'));
    }

    public function testRegisterAndGet(): void
    {
        $registry = new FormatterRegistry();
        $formatter = new FakeFormatter();

        $registry->register('fake_open', $formatter);

        $this->assertSame($formatter, $registry->get('fake_open'));
    }

    public function testRegisterOverwritesExistingEntry(): void
    {
        $registry = new FormatterRegistry();
        $first = new FakeFormatter();
        $second = new FakeFormatter();

        $registry->register('fake_open', $first);
        $registry->register('fake_open', $second);

        $this->assertSame($second, $registry->get('fake_open'));
    }

    public function testRegistryIsolatesTypes(): void
    {
        $registry = new FormatterRegistry();
        $formatter = new FakeFormatter();

        $registry->register('type_a', $formatter);

        $this->assertSame($formatter, $registry->get('type_a'));
        $this->assertNull($registry->get('type_b'));
    }
}
