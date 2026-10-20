<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

final class OpenCloseEntryTest extends TestCase
{
    public function testRootEntryOmitsParentId(): void
    {
        $entry = new OpenCloseEntry(
            'simple_entry_1',
            'simple_entry',
            'https://example.com/simple.json',
            ['message' => 'hello', 'value' => 42],
        );

        $this->assertSame('simple_entry_1', $entry->id);
        $this->assertSame('simple_entry', $entry->type);
        $this->assertSame('https://example.com/simple.json', $entry->schemaUrl);
        $this->assertSame('hello', $entry->context['message']);
        $this->assertSame(42, $entry->context['value']);
        $this->assertNull($entry->parentId);

        $this->assertSame([
            'id' => 'simple_entry_1',
            'type' => 'simple_entry',
            'schemaUrl' => 'https://example.com/simple.json',
            'context' => ['message' => 'hello', 'value' => 42],
        ], $entry->toArray());
    }

    public function testNestedEntryEmitsParentId(): void
    {
        $child = new OpenCloseEntry(
            'child_entry_1',
            'child_entry',
            'https://example.com/child.json',
            ['which' => 1],
            'parent_entry_1',
        );

        $this->assertSame('parent_entry_1', $child->parentId);
        $this->assertSame([
            'id' => 'child_entry_1',
            'type' => 'child_entry',
            'schemaUrl' => 'https://example.com/child.json',
            'context' => ['which' => 1],
            'parentId' => 'parent_entry_1',
        ], $child->toArray());
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $entry = new OpenCloseEntry('a_1', 'a', 'https://example.com/a.json', ['x' => 1], 'root_1');

        $this->assertSame($entry->toArray(), $entry->jsonSerialize());
    }
}
