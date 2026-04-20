<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

final class OpenCloseEntryTest extends TestCase
{
    public function testOpenCloseEntryWithoutNesting(): void
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
        $this->assertSame([], $entry->open);
        $this->assertNull($entry->parentId);

        // Test array serialization - open is empty, so no 'open' key should be added
        $array = $entry->toArray();
        $this->assertArrayNotHasKey('open', $array);
        $this->assertSame([
            'id' => 'simple_entry_1',
            'type' => 'simple_entry',
            'schemaUrl' => 'https://example.com/simple.json',
            'context' => ['message' => 'hello', 'value' => 42],
        ], $array);
    }

    public function testOpenCloseEntryWithSiblingChildren(): void
    {
        $child1 = new OpenCloseEntry(
            'child_1',
            'child_entry',
            'https://example.com/child.json',
            ['which' => 1],
            [],
            'parent_entry_1',
        );

        $child2 = new OpenCloseEntry(
            'child_2',
            'child_entry',
            'https://example.com/child.json',
            ['which' => 2],
            [],
            'parent_entry_1',
        );

        $entry = new OpenCloseEntry(
            'parent_entry_1',
            'parent_entry',
            'https://example.com/parent.json',
            ['parent' => true],
            [$child1, $child2],
        );

        $this->assertCount(2, $entry->open);
        $this->assertSame('child_1', $entry->open[0]->id);
        $this->assertSame('child_2', $entry->open[1]->id);
        $this->assertSame('parent_entry_1', $entry->open[0]->parentId);

        $array = $entry->toArray();
        $this->assertArrayHasKey('open', $array);
        $openArray = $array['open'];
        $this->assertIsArray($openArray);
        $this->assertCount(2, $openArray);
    }

    public function testDeepNesting(): void
    {
        $level3 = new OpenCloseEntry('level3_1', 'level3', 'https://example.com/3.json', ['level' => 3]);
        $level2 = new OpenCloseEntry('level2_1', 'level2', 'https://example.com/2.json', ['level' => 2], [$level3]);
        $level1 = new OpenCloseEntry('level1_1', 'level1', 'https://example.com/1.json', ['level' => 1], [$level2]);

        $this->assertSame('level1_1', $level1->id);
        $this->assertSame('level1', $level1->type);
        $this->assertSame(1, $level1->context['level']);

        $this->assertCount(1, $level1->open);
        $this->assertSame('level2_1', $level1->open[0]->id);
        $this->assertSame(2, $level1->open[0]->context['level']);

        $this->assertCount(1, $level1->open[0]->open);
        $this->assertSame('level3_1', $level1->open[0]->open[0]->id);
        $this->assertSame(3, $level1->open[0]->open[0]->context['level']);
        $this->assertSame([], $level1->open[0]->open[0]->open);

        // Test array serialization - level3 should not have nested open
        $array = $level1->toArray();
        $this->assertArrayHasKey('open', $array);
        /** @var list<array<string, mixed>> $level2List */
        $level2List = $array['open'];
        $level2Array = $level2List[0];
        $this->assertArrayHasKey('open', $level2Array);
        /** @var list<array<string, mixed>> $level3List */
        $level3List = $level2Array['open'];
        $level3Array = $level3List[0];
        $this->assertArrayNotHasKey('open', $level3Array);
    }
}
