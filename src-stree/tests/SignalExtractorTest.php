<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use PHPUnit\Framework\TestCase;

use function array_map;
use function explode;
use function strlen;

final class SignalExtractorTest extends TestCase
{
    private SignalExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new SignalExtractor();
    }

    public function testExtractSignalsBasic(): void
    {
        $context = ['method' => 'POST', 'uri' => '/api/orders', 'status' => 'active'];
        $result = $this->extractor->extractSignals($context);

        $this->assertStringContainsString('method=POST', $result);
        $this->assertStringContainsString('uri=/api/orders', $result);
        $this->assertStringContainsString('status=active', $result);
    }

    public function testExtractSignalsMaxFourWithOverflow(): void
    {
        $context = ['a' => 'v1', 'b' => 'v2', 'c' => 'v3', 'd' => 'v4', 'e' => 'v5', 'f' => 'v6'];
        $result = $this->extractor->extractSignals($context);

        $this->assertStringContainsString('a=v1', $result);
        $this->assertStringContainsString('b=v2', $result);
        $this->assertStringContainsString('c=v3', $result);
        $this->assertStringContainsString('d=v4', $result);
        $this->assertStringContainsString('(+2 more)', $result);
        $this->assertStringNotContainsString('e=', $result);
    }

    public function testExtractSignalsExcludesTimingKeys(): void
    {
        $context = [
            'executionTime' => 0.5,
            'responseTime' => 0.1,
            'duration' => 0.3,
            'processingTime' => 0.2,
            'connectionTime' => 0.05,
            'timestamp' => '2026-06-10T12:34:56+00:00',
            'name' => 'task',
        ];
        $result = $this->extractor->extractSignals($context);

        $this->assertStringNotContainsString('executionTime', $result);
        $this->assertStringNotContainsString('responseTime', $result);
        $this->assertStringNotContainsString('duration', $result);
        $this->assertStringNotContainsString('processingTime', $result);
        $this->assertStringNotContainsString('connectionTime', $result);
        $this->assertStringNotContainsString('timestamp', $result);
        $this->assertStringContainsString('name=task', $result);
    }

    public function testExtractSignalsExcludesIdKeys(): void
    {
        $context = ['id' => 'abc', 'openId' => 'xyz', 'schemaUrl' => 'http://example.com', 'name' => 'task'];
        $result = $this->extractor->extractSignals($context);

        $this->assertStringNotContainsString('id=', $result);
        $this->assertStringNotContainsString('openId=', $result);
        $this->assertStringNotContainsString('schemaUrl=', $result);
        $this->assertStringContainsString('name=task', $result);
    }

    public function testExtractSignalsExcludesNullValues(): void
    {
        $context = ['token' => null, 'method' => 'GET'];
        $result = $this->extractor->extractSignals($context);

        $this->assertStringNotContainsString('token', $result);
        $this->assertStringContainsString('method=GET', $result);
    }

    public function testExtractSignalsExcludesEmptyString(): void
    {
        $context = ['empty' => '', 'name' => 'value'];
        $result = $this->extractor->extractSignals($context);

        $this->assertStringNotContainsString('empty', $result);
        $this->assertStringContainsString('name=value', $result);
    }

    public function testExtractSignalsExcludesNumericZero(): void
    {
        $context = ['count' => 0, 'name' => 'value'];
        $result = $this->extractor->extractSignals($context);

        $this->assertStringNotContainsString('count=', $result);
        $this->assertStringContainsString('name=value', $result);
    }

    public function testExtractSignalsPreservesBooleans(): void
    {
        $context = ['success' => false, 'active' => true];
        $result = $this->extractor->extractSignals($context);

        $this->assertStringContainsString('success=false', $result);
        $this->assertStringContainsString('active=true', $result);
    }

    public function testExtractSignalsEmptyContext(): void
    {
        $result = $this->extractor->extractSignals([]);

        $this->assertSame('', $result);
    }

    public function testExtractSignalsAllExcluded(): void
    {
        $context = ['id' => 'abc', 'executionTime' => 0.5, 'openId' => 'xyz'];
        $result = $this->extractor->extractSignals($context);

        $this->assertSame('', $result);
    }

    public function testFqcnShortening(): void
    {
        $result = $this->extractor->formatValue('App\\Service\\OrderProcessor');

        $this->assertSame('OrderProcessor', $result);
    }

    public function testStringTruncation(): void
    {
        $longString = 'This is a very long string that exceeds forty characters in total';
        $result = $this->extractor->formatValue($longString);

        $this->assertIsString($result);
        $this->assertStringContainsString('…', $result);
        $this->assertLessThanOrEqual(41 + 3, strlen($result));
    }

    public function testScalarArrayInline(): void
    {
        $result = $this->extractor->formatValue(['a', 'b', 'c']);

        $this->assertSame('[a, b, c]', $result);
    }

    public function testLargeArrayShowsCount(): void
    {
        $arr = ['very long item one', 'very long item two', 'very long item three', 'four', 'five', 'six'];
        $result = $this->extractor->formatValue($arr);

        $this->assertIsString($result);
        $this->assertStringContainsString('items', $result);
    }

    public function testArrayPartialOverflowPreservesFirstItems(): void
    {
        $arr = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta'];
        $result = $this->extractor->formatValue($arr);

        // Keeps as many as fit under MAX_STRING_LENGTH, then "+N items".
        $this->assertIsString($result);
        $this->assertStringStartsWith('[alpha', $result);
        $this->assertStringContainsString(' items]', $result);
    }

    public function testAssocArrayPartialOverflowShowsKeys(): void
    {
        $arr = [
            'orderId' => 'x',
            'status' => 'y',
            'inventory' => 'z',
            'payment' => 'w',
            'shipping' => 'v',
        ];
        $result = $this->extractor->formatValue($arr);

        $this->assertIsString($result);
        // Partial overflow: starts with "[orderId, status..." and ends with "+N items]".
        $this->assertStringStartsWith('[orderId', $result);
        $this->assertStringContainsString(' items]', $result);
    }

    public function testAssocArraySmallShowsAllKeysInBrackets(): void
    {
        $arr = ['name' => 'a', 'email' => 'b'];
        $result = $this->extractor->formatValue($arr);

        $this->assertSame('[name, email]', $result);
    }

    public function testExtractCloseDiffNewKey(): void
    {
        $open = ['operation' => 'checkout'];
        $close = ['operation' => 'checkout', 'success' => true];

        $result = $this->extractor->extractCloseDiff($open, $close);

        $this->assertSame('success=true', $result);
    }

    public function testExtractCloseDiffChangedKey(): void
    {
        $open = ['status' => 'pending'];
        $close = ['status' => 'done'];

        $result = $this->extractor->extractCloseDiff($open, $close);

        $this->assertSame('status=pending→done', $result);
    }

    public function testExtractCloseDiffIdentical(): void
    {
        $open = ['operation' => 'validate'];
        $close = ['operation' => 'validate'];

        $result = $this->extractor->extractCloseDiff($open, $close);

        $this->assertSame('', $result);
    }

    public function testExtractCloseDiffMaxTwo(): void
    {
        $open = [];
        $close = ['a' => 'x', 'b' => 'y', 'c' => 'z'];

        $result = $this->extractor->extractCloseDiff($open, $close);

        // Two space-joined key=value pairs: "a=x b=y"
        $parts = explode(' ', $result);
        $this->assertCount(2, $parts);
    }

    public function testExtractCloseDiffPrefersExitOverRedundantFinal(): void
    {
        $open = ['input' => 'App\\Input\\HelloInput'];
        $close = ['exit' => 'success', 'final' => 'App\\Final\\Hello'];

        $result = $this->extractor->extractCloseDiff($open, $close);

        $this->assertSame('exit=success', $result);
    }

    public function testHasFailureIndicatorSuccessFalse(): void
    {
        $this->assertTrue($this->extractor->hasFailureIndicator(['success' => false]));
    }

    public function testHasFailureIndicatorSuccessTrue(): void
    {
        $this->assertFalse($this->extractor->hasFailureIndicator(['success' => true]));
    }

    public function testHasFailureIndicatorNoIndicator(): void
    {
        $this->assertFalse($this->extractor->hasFailureIndicator(['result' => 'ok']));
    }

    public function testExpandFullFlat(): void
    {
        $context = ['name' => 'test', 'value' => 42];
        $result = $this->extractor->expandFull($context);

        $this->assertContains('name: test', $result);
        $this->assertContains('value: 42', $result);
    }

    public function testExpandFullNested(): void
    {
        $context = ['info' => ['host' => 'localhost', 'port' => 3306]];
        $result = $this->extractor->expandFull($context);

        $this->assertContains('info.host: localhost', $result);
        $this->assertContains('info.port: 3306', $result);
    }

    public function testExpandFullUsesBracketNotationForLists(): void
    {
        $context = [
            'xdebugTrace' => [
                ['path' => '/tmp/profile_a.xt'],
                ['path' => '/tmp/profile_b.xt'],
            ],
        ];

        $result = $this->extractor->expandFull($context);

        $this->assertContains('xdebugTrace[0].path: /tmp/profile_a.xt', $result);
        $this->assertContains('xdebugTrace[1].path: /tmp/profile_b.xt', $result);
    }

    public function testExpandFullExcludesTimingKeys(): void
    {
        $context = ['executionTime' => 0.5, 'name' => 'task'];
        $result = $this->extractor->expandFull($context);

        $keys = array_map(static fn ($l) => explode(':', $l)[0], $result);
        $this->assertNotContains('executionTime', $keys);
        $this->assertContains('name', $keys);
    }
}
