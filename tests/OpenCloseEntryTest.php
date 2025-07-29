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
        $this->assertNull($entry->open);

        // Test array serialization - open is null, so no 'open' key should be added
        $array = $entry->toArray();
        $this->assertArrayNotHasKey('open', $array);
        $this->assertSame([
            'id' => 'simple_entry_1',
            'type' => 'simple_entry',
            '$schema' => 'https://example.com/simple.json',
            'context' => ['message' => 'hello', 'value' => 42],
        ], $array);
    }

    public function testOpenCloseEntryWithNesting(): void
    {
        $nested = new OpenCloseEntry(
            'nested_entry_1',
            'nested_entry',
            'https://example.com/nested.json',
            ['nested' => true],
        );

        $entry = new OpenCloseEntry(
            'parent_entry_1',
            'parent_entry',
            'https://example.com/parent.json',
            ['parent' => true],
            $nested,
        );

        $this->assertSame('parent_entry_1', $entry->id);
        $this->assertSame('parent_entry', $entry->type);
        $this->assertSame('https://example.com/parent.json', $entry->schemaUrl);
        $this->assertSame(true, $entry->context['parent']);

        $this->assertNotNull($entry->open);
        $this->assertSame('nested_entry_1', $entry->open->id);
        $this->assertSame('nested_entry', $entry->open->type);
        $this->assertSame('https://example.com/nested.json', $entry->open->schemaUrl);
        $this->assertSame(true, $entry->open->context['nested']);

        // Test array serialization - open is not null, so 'open' key should be added
        $array = $entry->toArray();
        $this->assertArrayHasKey('open', $array);
        if (isset($array['open'])) {
            /** @var array<string, mixed> $openArray */
            $openArray = $array['open'];
            $this->assertSame('nested_entry_1', $openArray['id']);
            $this->assertSame('nested_entry', $openArray['type']);
        }
    }

    public function testDeepNesting(): void
    {
        $level3 = new OpenCloseEntry('level3_1', 'level3', 'https://example.com/3.json', ['level' => 3]);
        $level2 = new OpenCloseEntry('level2_1', 'level2', 'https://example.com/2.json', ['level' => 2], $level3);
        $level1 = new OpenCloseEntry('level1_1', 'level1', 'https://example.com/1.json', ['level' => 1], $level2);

        $this->assertSame('level1_1', $level1->id);
        $this->assertSame('level1', $level1->type);
        $this->assertSame(1, $level1->context['level']);

        $this->assertNotNull($level1->open);
        $this->assertSame('level2_1', $level1->open->id);
        $this->assertSame('level2', $level1->open->type);
        $this->assertSame(2, $level1->open->context['level']);

        $this->assertNotNull($level1->open->open);
        $this->assertSame('level3_1', $level1->open->open->id);
        $this->assertSame('level3', $level1->open->open->type);
        $this->assertSame(3, $level1->open->open->context['level']);
        $this->assertNull($level1->open->open->open);

        // Test array serialization - level3 should not have nested open
        $array = $level1->toArray();
        $this->assertArrayHasKey('open', $array);
        if (isset($array['open'])) {
            /** @var array<string, mixed> $level2Array */
            $level2Array = $array['open'];
            $this->assertArrayHasKey('open', $level2Array);
            if (isset($level2Array['open'])) {
                /** @var array<string, mixed> $level3Array */
                $level3Array = $level2Array['open'];
                $this->assertArrayNotHasKey('open', $level3Array);
            }
        }
    }
}
