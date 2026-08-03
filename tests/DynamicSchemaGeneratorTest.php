<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

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

    public function testGenerateCombinedSchemaUsesTreeOnlyPublicShape(): void
    {
        $generator = new DynamicSchemaGenerator($this->schemaDirectory);
        $schema = $generator->generateCombinedSchema();
        $properties = $this->expectArray($schema['properties'] ?? null);
        $definitions = $this->expectArray($schema['definitions'] ?? null);
        $openProperty = $this->expectArray($properties['open'] ?? null);
        $eventsProperty = $this->expectArray($properties['events'] ?? null);
        $closeProperty = $this->expectArray($properties['close'] ?? null);
        $contextType = $this->expectArray($definitions['contextType'] ?? null);

        $this->assertSame(['$schema', 'open'], $schema['required']);
        $this->assertArrayHasKey('$schema', $properties);
        $this->assertArrayNotHasKey('schemaUrl', $properties);
        $this->assertSame('array', $openProperty['type']);
        $this->assertArrayNotHasKey('minItems', $openProperty);
        $this->assertSame('array', $eventsProperty['type']);
        $this->assertSame('array', $closeProperty['type']);
        $this->assertSame('^[a-z_]+$', $contextType['pattern']);

        $this->assertOpenEntryDefinition($definitions['openEntry'] ?? null);
        $this->assertLeafEntryDefinition($definitions['eventEntry'] ?? null);
        $this->assertLeafEntryDefinition($definitions['closeEntry'] ?? null, true);

        $this->assertSame(
            '#/definitions/openEntry',
            $this->expectArray($openProperty['items'] ?? null)['$ref'],
        );
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
        $allOf = $this->expectArray($definition['allOf'] ?? null);

        $this->assertSame('object', $definition['type']);
        $this->assertSame(['id', 'type', 'schemaUrl', 'context'], $definition['required']);
        $this->assertArrayHasKey('schemaUrl', $properties);
        $this->assertArrayHasKey('context', $properties);
        $this->assertArrayHasKey('open', $properties);
        $this->assertArrayHasKey('events', $properties);
        $this->assertArrayHasKey('close', $properties);
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

    private function assertLeafEntryDefinition(mixed $definition, bool $allowsProfile = false): void
    {
        $definition = $this->expectArray($definition);
        $properties = $this->expectArray($definition['properties'] ?? null);
        $allOf = $this->expectArray($definition['allOf'] ?? null);

        $this->assertSame('object', $definition['type']);
        $this->assertSame(['id', 'type', 'schemaUrl', 'context'], $definition['required']);
        $this->assertArrayHasKey('schemaUrl', $properties);
        $this->assertArrayHasKey('context', $properties);
        $this->assertArrayNotHasKey('open', $properties);
        $this->assertArrayNotHasKey('events', $properties);
        $this->assertArrayNotHasKey('close', $properties);
        $this->assertArrayHasKey('openId', $properties);
        $this->assertSame('#/definitions/contextType', $this->expectArray($properties['type'] ?? null)['$ref']);

        if ($allowsProfile) {
            $this->assertArrayHasKey('profile', $properties);
        } else {
            $this->assertArrayNotHasKey('profile', $properties);
        }

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
