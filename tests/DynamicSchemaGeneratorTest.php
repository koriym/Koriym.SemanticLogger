<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function array_keys;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_string;
use function json_decode;
use function mkdir;
use function rmdir;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class DynamicSchemaGeneratorTest extends TestCase
{
    private string $schemaDirectory;

    protected function setUp(): void
    {
        $this->schemaDirectory = sys_get_temp_dir() . '/semantic-schema-' . uniqid('', true);
        mkdir($this->schemaDirectory);

        file_put_contents(
            $this->schemaDirectory . '/http-request.json',
            '{"type":"object","properties":{"method":{"type":"string"}},"additionalProperties":true}',
        );
        file_put_contents(
            $this->schemaDirectory . '/cache-operation.json',
            '{"type":"object","properties":{"key":{"type":"string"}},"additionalProperties":true}',
        );
    }

    protected function tearDown(): void
    {
        unlink($this->schemaDirectory . '/http-request.json');
        unlink($this->schemaDirectory . '/cache-operation.json');
        rmdir($this->schemaDirectory);
    }

    public function testGenerateCombinedSchemaUsesOrderedEntriesShape(): void
    {
        $generator = new DynamicSchemaGenerator($this->schemaDirectory);
        $schema = $generator->generateCombinedSchema();
        $properties = $this->expectArray($schema['properties'] ?? null);
        $definitions = $this->expectArray($schema['definitions'] ?? null);
        $entriesProperty = $this->expectArray($properties['entries'] ?? null);
        $closeProperty = $this->expectArray($properties['close'] ?? null);
        $contextType = $this->expectArray($definitions['contextType'] ?? null);

        $this->assertSame(['$schema', 'entries'], $schema['required']);
        $this->assertSame(['$schema', 'entries', 'close', 'links'], array_keys($properties));
        $this->assertSame('array', $entriesProperty['type']);
        $this->assertSame('#/definitions/entry', $this->expectArray($entriesProperty['items'] ?? null)['$ref']);
        $this->assertSame('array', $closeProperty['type']);
        $this->assertSame('#/definitions/closeEntry', $this->expectArray($closeProperty['items'] ?? null)['$ref']);
        $this->assertSame('^[a-z_]+$', $contextType['pattern']);
        $this->assertSame(
            ['schemaUrl', 'operationId', 'openIdReference', 'contextType', 'entry', 'openEntry', 'eventEntry', 'closeEntry'],
            array_keys($definitions),
        );

        $entry = $this->expectArray($definitions['entry'] ?? null);
        $this->assertSame(
            [['$ref' => '#/definitions/openEntry'], ['$ref' => '#/definitions/eventEntry']],
            $entry['oneOf'],
        );

        $this->assertOpenEntryDefinition($definitions['openEntry'] ?? null);
        $this->assertEventEntryDefinition($definitions['eventEntry'] ?? null);
        $this->assertCloseEntryDefinition($definitions['closeEntry'] ?? null);
    }

    public function testGenerateCombinedSchemaReusesPublicSchemaUrlRule(): void
    {
        $generator = new DynamicSchemaGenerator($this->schemaDirectory);
        $schema = $generator->generateCombinedSchema();
        $generatedDefinitions = $this->expectArray($schema['definitions'] ?? null);
        $generatedSchemaUrl = $this->expectArray($generatedDefinitions['schemaUrl'] ?? null);

        $staticSchemaJson = file_get_contents(dirname(__DIR__) . '/docs/schemas/semantic-log.json');
        self::assertNotFalse($staticSchemaJson);
        $staticSchema = $this->expectArray(json_decode($staticSchemaJson, true));
        $staticDefinitions = $this->expectArray($staticSchema['definitions'] ?? null);
        $staticSchemaUrl = $this->expectArray($staticDefinitions['schemaUrl'] ?? null);

        $this->assertSame($staticSchemaUrl, $generatedSchemaUrl);
    }

    private function assertOpenEntryDefinition(mixed $definition): void
    {
        $definition = $this->expectArray($definition);
        $properties = $this->expectArray($definition['properties'] ?? null);
        $children = $this->expectArray($properties['children'] ?? null);

        $this->assertSame('object', $definition['type']);
        $this->assertSame(['kind', 'id', 'type', 'schemaUrl', 'context', 'children'], $definition['required']);
        $this->assertSame(['const' => 'open'], $properties['kind']);
        $this->assertSame('array', $children['type']);
        $this->assertSame('#/definitions/entry', $this->expectArray($children['items'] ?? null)['$ref']);
        $this->assertSame('#/definitions/closeEntry', $this->expectArray($properties['close'] ?? null)['$ref']);
        $this->assertArrayNotHasKey('openId', $properties);
        $this->assertTypeConditions($definition);
    }

    private function assertEventEntryDefinition(mixed $definition): void
    {
        $definition = $this->expectArray($definition);
        $properties = $this->expectArray($definition['properties'] ?? null);

        $this->assertSame('object', $definition['type']);
        $this->assertSame(['kind', 'id', 'type', 'schemaUrl', 'context'], $definition['required']);
        $this->assertSame(['const' => 'event'], $properties['kind']);
        $this->assertArrayNotHasKey('openId', $properties);
        $this->assertArrayNotHasKey('children', $properties);
        $this->assertArrayNotHasKey('close', $properties);
        $this->assertTypeConditions($definition);
    }

    private function assertCloseEntryDefinition(mixed $definition): void
    {
        $definition = $this->expectArray($definition);
        $properties = $this->expectArray($definition['properties'] ?? null);

        $this->assertSame('object', $definition['type']);
        $this->assertSame(['id', 'type', 'schemaUrl', 'context'], $definition['required']);
        $this->assertArrayNotHasKey('kind', $properties);
        $this->assertArrayHasKey('openId', $properties);
        $this->assertArrayHasKey('profile', $properties);
        $this->assertTypeConditions($definition);
    }

    /** @param array<mixed> $definition */
    private function assertTypeConditions(array $definition): void
    {
        $properties = $this->expectArray($definition['properties'] ?? null);
        $allOf = $this->expectArray($definition['allOf'] ?? null);

        $this->assertArrayHasKey('schemaUrl', $properties);
        $this->assertArrayHasKey('context', $properties);
        $this->assertSame('#/definitions/contextType', $this->expectArray($properties['type'] ?? null)['$ref']);
        $this->assertCount(2, $allOf);

        $refs = [];
        foreach ($allOf as $condition) {
            $condition = $this->expectArray($condition);
            $then = $this->expectArray($condition['then'] ?? null);
            $conditionProperties = $this->expectArray($then['properties'] ?? null);
            $context = $this->expectArray($conditionProperties['context'] ?? null);
            $ref = $context['$ref'] ?? null;
            if (is_string($ref)) {
                $refs[] = $ref;
            }
        }

        $this->assertSame(
            ['./schemas/cache-operation.json', './schemas/http-request.json'],
            $this->sortRefs($refs),
        );
    }

    /**
     * @param array<string> $refs
     *
     * @return list<string>
     */
    private function sortRefs(array $refs): array
    {
        sort($refs);

        return $refs;
    }

    /** @return array<mixed> */
    private function expectArray(mixed $value): array
    {
        $this->assertIsArray($value);

        return $value;
    }
}
