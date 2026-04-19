<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use PHPUnit\Framework\TestCase;

final class TreeNodeTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $node = new TreeNode('test_1', 'test_type', ['key' => 'value'], 0.005);

        $this->assertSame('test_1', $node->id);
        $this->assertSame('test_type', $node->type);
        $this->assertSame(['key' => 'value'], $node->context);
        $this->assertSame(0.005, $node->executionTime);
        $this->assertEmpty($node->children);
    }

    public function testAddChild(): void
    {
        $parent = new TreeNode('parent_1', 'parent', []);
        $child = new TreeNode('child_1', 'child', []);

        $parent->addChild($child);

        $this->assertCount(1, $parent->children);
        $this->assertSame($child, $parent->children[0]);
    }

    public function testGetDisplayName(): void
    {
        $node = new TreeNode('test_1', 'test_type', []);

        $this->assertSame('test_type', $node->getDisplayName());
    }

    public function testFormatExecutionTimeMicroseconds(): void
    {
        $node = new TreeNode('test_1', 'test_type', [], 0.0005); // 0.5ms

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('[500.0μs]', $displayLine);
    }

    public function testFormatExecutionTimeMilliseconds(): void
    {
        $node = new TreeNode('test_1', 'test_type', [], 0.025); // 25ms

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('[25.0ms]', $displayLine);
    }

    public function testFormatExecutionTimeSeconds(): void
    {
        $node = new TreeNode('test_1', 'test_type', [], 1.5); // 1.5s

        $displayLine = $node->getDisplayLine();

        $this->assertStringContainsString('[1.5s]', $displayLine);
    }

    public function testZeroExecutionTimeOmitted(): void
    {
        $node = new TreeNode('test_1', 'test_type', [], 0.0);

        $displayLine = $node->getDisplayLine();

        $this->assertStringNotContainsString('[', $displayLine);
    }

    public function testGenericSignalExtraction(): void
    {
        $context = ['method' => 'POST', 'uri' => '/api/orders'];
        $node = new TreeNode('n1', 'http_request', $context);

        $line = $node->getDisplayLine();

        $this->assertStringContainsString('http_request', $line);
        $this->assertStringContainsString('method=POST', $line);
        $this->assertStringContainsString('uri=/api/orders', $line);
    }

    public function testFqcnShortening(): void
    {
        $context = ['fromClass' => 'Be\\Skeleton\\Input\\HelloInput'];
        $node = new TreeNode('n1', 'metamorphosis', $context);

        $line = $node->getDisplayLine();

        $this->assertStringContainsString('fromClass=HelloInput', $line);
        $this->assertStringNotContainsString('Be\\Skeleton', $line);
    }

    public function testStringTruncation(): void
    {
        $context = ['message' => 'This is a very long string that definitely exceeds 40 chars'];
        $node = new TreeNode('n1', 'some_type', $context);

        $line = $node->getDisplayLine();

        $this->assertStringContainsString('…', $line);
    }

    public function testBooleanPreserved(): void
    {
        $context = ['success' => false, 'active' => true];
        $node = new TreeNode('n1', 'business_logic', $context);

        $line = $node->getDisplayLine();

        $this->assertStringContainsString('success=false', $line);
        $this->assertStringContainsString('active=true', $line);
    }

    public function testNullValuesExcluded(): void
    {
        $context = ['token' => null, 'method' => 'JWT'];
        $node = new TreeNode('n1', 'authentication_request', $context);

        $line = $node->getDisplayLine();

        $this->assertStringNotContainsString('token', $line);
        $this->assertStringContainsString('method=JWT', $line);
    }

    public function testEmptyStringExcluded(): void
    {
        $context = ['empty' => '', 'name' => 'value'];
        $node = new TreeNode('n1', 'some_type', $context);

        $line = $node->getDisplayLine();

        $this->assertStringNotContainsString('empty=', $line);
        $this->assertStringContainsString('name=value', $line);
    }

    public function testNumericZeroExcluded(): void
    {
        $context = ['count' => 0, 'name' => 'something'];
        $node = new TreeNode('n1', 'some_type', $context);

        $line = $node->getDisplayLine();

        $this->assertStringNotContainsString('count=0', $line);
        $this->assertStringContainsString('name=something', $line);
    }

    public function testMaxThreeSignalsWithOverflow(): void
    {
        $context = [
            'a' => 'alpha',
            'b' => 'beta',
            'c' => 'gamma',
            'd' => 'delta',
            'e' => 'epsilon',
        ];
        $node = new TreeNode('n1', 'some_type', $context);

        $line = $node->getDisplayLine();

        $this->assertStringContainsString('a=alpha', $line);
        $this->assertStringContainsString('b=beta', $line);
        $this->assertStringContainsString('c=gamma', $line);
        $this->assertStringContainsString('(+2 more)', $line);
        $this->assertStringNotContainsString('d=', $line);
    }

    public function testTimingKeysExcludedFromSignals(): void
    {
        $context = ['executionTime' => 0.5, 'name' => 'task'];
        $node = new TreeNode('n1', 'some_type', $context, 0.5);

        $line = $node->getDisplayLine();

        $this->assertStringNotContainsString('executionTime', $line);
        $this->assertStringContainsString('name=task', $line);
    }

    public function testIdKeysExcludedFromSignals(): void
    {
        $context = ['id' => 'abc123', 'openId' => 'xyz', 'name' => 'task'];
        $node = new TreeNode('n1', 'some_type', $context);

        $line = $node->getDisplayLine();

        $this->assertStringNotContainsString('id=', $line);
        $this->assertStringNotContainsString('openId=', $line);
        $this->assertStringContainsString('name=task', $line);
    }

    public function testUnknownTypeWithContextKeys(): void
    {
        $context = ['fromClass' => 'Be\\Skeleton\\Input\\HelloInput', 'beAttribute' => '#[Be(...)]'];
        $node = new TreeNode('n1', 'metamorphosis_open', $context);
        // No close set, so type won't be stripped
        $line = $node->getDisplayLine();

        $this->assertStringContainsString('metamorphosis_open', $line);
        $this->assertStringContainsString('fromClass=HelloInput', $line);
    }

    public function testOpenSuffixStrippedWhenClosedSet(): void
    {
        $node = new TreeNode('n1', 'metamorphosis_open', ['name' => 'test']);
        $node->setClose('metamorphosis_close', []);

        $line = $node->getDisplayLine();

        $this->assertStringContainsString('metamorphosis', $line);
        $this->assertStringNotContainsString('_open', $line);
    }

    public function testCloseDiffNewKey(): void
    {
        $node = new TreeNode('n1', 'business_logic', ['operation' => 'checkout']);
        $node->setClose('business_logic_close', ['operation' => 'checkout', 'success' => true]);

        $line = $node->getDisplayLine();

        $this->assertStringContainsString('→ success=true', $line);
    }

    public function testCloseDiffChangedKey(): void
    {
        $node = new TreeNode('n1', 'some_type', ['status' => 'pending']);
        $node->setClose('some_type_close', ['status' => 'done']);

        $line = $node->getDisplayLine();

        $this->assertStringContainsString('→ status=pending→done', $line);
    }

    public function testCloseDiffOmittedWhenIdentical(): void
    {
        $node = new TreeNode('n1', 'some_type', ['operation' => 'validate']);
        $node->setClose('some_type_close', ['operation' => 'validate']);

        $line = $node->getDisplayLine();

        $this->assertStringNotContainsString('→', $line);
    }

    public function testStatusFailedWhenSuccessFalse(): void
    {
        $node = new TreeNode('n1', 'some_type', ['operation' => 'charge']);
        $node->setClose('some_type_close', ['success' => false]);
        $node->status = 'Failed';

        $line = $node->getDisplayLine();

        $this->assertStringContainsString(': Failed', $line);
    }

    public function testStatusUnclosedWhenNoClose(): void
    {
        $node = new TreeNode('n1', 'some_type', ['operation' => 'process']);
        $node->status = 'unclosed';

        $line = $node->getDisplayLine();

        $this->assertStringContainsString(': unclosed', $line);
    }

    public function testStatusSilentOnSuccess(): void
    {
        $node = new TreeNode('n1', 'some_type', ['operation' => 'ok']);
        $node->setClose('some_type_close', ['success' => true]);
        // status stays ''

        $line = $node->getDisplayLine();

        $this->assertStringNotContainsString(': ', $line);
    }

    public function testEventMarker(): void
    {
        $node = new TreeNode('n1', 'http_request', ['method' => 'POST']);
        $node->isEvent = true;

        $line = $node->getDisplayLine();

        $this->assertStringContainsString('[event]', $line);
    }

    public function testNoEventMarkerByDefault(): void
    {
        $node = new TreeNode('n1', 'some_type', []);
        $node->setClose('some_type_close', []);

        $line = $node->getDisplayLine();

        $this->assertStringNotContainsString('[event]', $line);
    }
}
