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

    public function testGenerateCombinedSchemaUsesLogShape(): void
    {
        $generator = new DynamicSchemaGenerator($this->schemaDirectory);
        $schema = $generator->generateCombinedSchema();
        $properties = $this->expectArray($schema['properties'] ?? null);
        $definitions = $this->expectArray($schema['definitions'] ?? null);
        $logProperty = $this->expectArray($properties['log'] ?? null);
        $typesProperty = $this->expectArray($properties['types'] ?? null);
        $contextType = $this->expectArray($definitions['contextType'] ?? null);

        $this->assertSame(['$schema', 'log'], $schema['required']);
        $this->assertSame(['$schema', 'log', 'types', 'links'], array_keys($properties));
        $this->assertSame('array', $logProperty['type']);
        $this->assertSame('#/definitions/rootRecord', $this->expectArray($logProperty['items'] ?? null)['$ref']);
        $this->assertSame('object', $typesProperty['type']);
        $this->assertSame('#/definitions/schemaUrl', $this->expectArray($typesProperty['additionalProperties'] ?? null)['$ref']);
        $this->assertSame('^[a-z_]+$', $contextType['pattern']);
        $this->assertSame(
            ['schemaUrl', 'operationId', 'openIdReference', 'contextType', 'rootRecord', 'record', 'close', 'orphanClose'],
            array_keys($definitions),
        );

        $rootRecord = $this->expectArray($definitions['rootRecord'] ?? null);
        $this->assertSame(
            [['$ref' => '#/definitions/record'], ['$ref' => '#/definitions/orphanClose']],
            $rootRecord['oneOf'],
        );

        $this->assertRecordDefinition($definitions['record'] ?? null);
        $this->assertCloseDefinition($definitions['close'] ?? null);
        $this->assertOrphanCloseDefinition($definitions['orphanClose'] ?? null);
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

    private function assertRecordDefinition(mixed $definition): void
    {
        $definition = $this->expectArray($definition);
        $properties = $this->expectArray($definition['properties'] ?? null);
        $log = $this->expectArray($properties['log'] ?? null);

        $this->assertSame('object', $definition['type']);
        $this->assertSame(['type', 'context'], $definition['required']);
        $this->assertArrayNotHasKey('kind', $properties);
        $this->assertArrayNotHasKey('openId', $properties);
        $this->assertSame('array', $log['type']);
        $this->assertSame('#/definitions/record', $this->expectArray($log['items'] ?? null)['$ref']);
        $this->assertSame('#/definitions/close', $this->expectArray($properties['close'] ?? null)['$ref']);
        $this->assertArrayHasKey('profile', $properties);
        $this->assertTypeConditions($definition);
    }

    private function assertCloseDefinition(mixed $definition): void
    {
        $definition = $this->expectArray($definition);
        $properties = $this->expectArray($definition['properties'] ?? null);

        $this->assertSame('object', $definition['type']);
        $this->assertSame(['type', 'context'], $definition['required']);
        $this->assertArrayNotHasKey('kind', $properties);
        $this->assertArrayNotHasKey('openId', $properties);
        $this->assertArrayNotHasKey('log', $properties);
        $this->assertArrayNotHasKey('close', $properties);
        $this->assertArrayHasKey('profile', $properties);
        $this->assertTypeConditions($definition);
    }

    private function assertOrphanCloseDefinition(mixed $definition): void
    {
        $definition = $this->expectArray($definition);
        $properties = $this->expectArray($definition['properties'] ?? null);

        $this->assertSame('object', $definition['type']);
        $this->assertSame(['type', 'context', 'openId'], $definition['required']);
        $this->assertArrayNotHasKey('kind', $properties);
        $this->assertArrayHasKey('openId', $properties);
        $this->assertArrayNotHasKey('log', $properties);
        $this->assertArrayNotHasKey('close', $properties);
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
