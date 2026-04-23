<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function is_array;
use function mkdir;
use function rmdir;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;
use function uniqid;

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

        $this->assertSame(['$schema', 'open'], $schema['required']);
        $this->assertArrayHasKey('$schema', $properties);
        $this->assertArrayNotHasKey('schemaUrl', $properties);
        $this->assertSame('array', $openProperty['type']);
        $this->assertSame('array', $eventsProperty['type']);
        $this->assertSame('array', $closeProperty['type']);

        $this->assertTreeEntryDefinition($definitions['openEntry'] ?? null, false);
        $this->assertTreeEntryDefinition($definitions['eventEntry'] ?? null, true);
        $this->assertTreeEntryDefinition($definitions['closeEntry'] ?? null, true);

        $this->assertSame(
            '#/definitions/openEntry',
            $this->expectArray($openProperty['items'] ?? null)['$ref'],
        );
    }

    private function assertTreeEntryDefinition(mixed $definition, bool $allowsDiagnosticOpenId): void
    {
        $definition = $this->expectArray($definition);
        $properties = $this->expectArray($definition['properties'] ?? null);
        $allOf = $this->expectArray($definition['allOf'] ?? null);

        $this->assertSame('object', $definition['type']);
        $this->assertSame(['id', 'type', 'schemaUrl', 'context'], $definition['required']);
        $this->assertArrayHasKey('schemaUrl', $properties);
        $this->assertArrayHasKey('context', $properties);

        if ($allowsDiagnosticOpenId) {
            $this->assertArrayHasKey('openId', $properties);
        } else {
            $this->assertArrayHasKey('open', $properties);
            $this->assertArrayHasKey('events', $properties);
            $this->assertArrayHasKey('close', $properties);
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

    /** @param array<string> $refs
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
