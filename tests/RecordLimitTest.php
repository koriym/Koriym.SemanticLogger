<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function array_column;
use function array_reverse;
use function count;

/**
 * A session stops recording at its limit instead of growing without bound,
 * and says so with one truncation marker at flush.
 *
 * @psalm-import-type PublicRecord from Types
 */
final class RecordLimitTest extends TestCase
{
    public function testRecordedIntervalsKeepTheirClosesPastTheLimit(): void
    {
        $logger = new SemanticLogger(3);
        $outer = $logger->open(new FakeContext('outer'));
        $logger->event(new FakeContext('note'));
        $inner = $logger->open(new FakeContext('inner'));
        $logger->event(new FakeContext('dropped event'));
        $unrecorded = $logger->open(new FakeContext('dropped open'));
        $logger->close(new FakeContext('dropped close'), $unrecorded);
        $logger->close(new FakeContext('inner done'), $inner);
        $logger->close(new FakeContext('outer done'), $outer);

        $log = $logger->flush()->toArray()['log'];

        $this->assertCount(2, $log);
        $root = $log[0];
        $this->assertSame('outer done', $root['close']['context']['message'] ?? null);
        $children = $root['log'] ?? [];
        $this->assertCount(2, $children);
        $inner = $children[1];
        $innerClose = $inner['close'] ?? null;
        $this->assertIsArray($innerClose);
        $this->assertSame(['message' => 'inner done', 'value' => 0], $innerClose['context']);
        $this->assertArrayNotHasKey('log', $inner, 'nothing past the limit was recorded inside inner');
        $this->assertSame(CoreSchema::TRUNCATED_TYPE, $log[1]['type']);
        $this->assertSame(['limit' => 3, 'dropped' => 3, 'firstDroppedType' => FakeContext::TYPE], $log[1]['context']);
    }

    public function testClosingAnUnrecordedOpenIsNotAProtocolViolation(): void
    {
        $logger = new SemanticLogger(1);
        $kept = $logger->open(new FakeContext('kept'));
        $lost = $logger->open(new FakeContext('lost'));
        $logger->close(new FakeContext('lost done'), $lost);
        $logger->close(new FakeContext('kept done'), $kept);

        $types = array_column($logger->flush()->toArray()['log'], 'type');

        $this->assertNotContains(CoreSchema::DIAGNOSTIC_TYPE, $types);
    }

    public function testDeepNestingPastTheLimitStillPairsClosesInLifoOrder(): void
    {
        $logger = new SemanticLogger(1);
        $kept = $logger->open(new FakeContext('kept'));
        $lost = [];
        for ($i = 0; $i < 50; $i++) {
            $lost[] = $logger->open(new FakeContext('lost', $i));
        }

        // Closing the recorded open while unrecorded ones are still open skips
        // over them: a LIFO violation, dropped (the session is full) — not a pop.
        $logger->close(new FakeContext('too early'), $kept);
        foreach (array_reverse($lost) as $id) {
            $logger->close(new FakeContext('lost done'), $id);
        }

        $logger->close(new FakeContext('kept done'), $kept);

        $log = $logger->flush()->toArray()['log'];

        $this->assertCount(2, $log);
        $this->assertSame(['message' => 'kept done', 'value' => 0], $log[0]['close']['context'] ?? null);
        $this->assertSame(['limit' => 1, 'dropped' => 101, 'firstDroppedType' => FakeContext::TYPE], $log[1]['context']);
    }

    public function testRejectedClosesCountAgainstTheLimit(): void
    {
        $logger = new SemanticLogger(2);
        for ($i = 0; $i < 5; $i++) {
            $logger->close(new FakeContext('stray'), 'example_event_99');
        }

        $log = $logger->flush()->toArray()['log'];

        $this->assertCount(3, $log);
        $this->assertSame(CoreSchema::DIAGNOSTIC_TYPE, $log[1]['type']);
        $this->assertSame(['limit' => 2, 'dropped' => 3, 'firstDroppedType' => CoreSchema::DIAGNOSTIC_TYPE], $log[2]['context']);
    }

    public function testOpensPastTheLimitLeftOpenAreNotReportedAsUnclosed(): void
    {
        $logger = new SemanticLogger(1);
        $logger->event(new FakeContext('kept'));
        $logger->open(new FakeContext('lost and never closed'));

        $types = array_column($logger->flush()->toArray()['log'], 'type');

        $this->assertSame([FakeContext::TYPE, CoreSchema::TRUNCATED_TYPE], $types);
    }

    public function testRecordsPastTheLimitAreNeverSerialized(): void
    {
        $logger = new SemanticLogger(1);
        CountingContext::$serialized = 0;
        $id = $logger->open(new CountingContext());
        $logger->event(new CountingContext());
        $inner = $logger->open(new CountingContext());
        $logger->close(new CountingContext(), $inner);
        $logger->close(new CountingContext(), $id);

        $this->assertSame(2, CountingContext::$serialized, 'only the recorded open and its close are serialized');
    }

    public function testUnclosedDiagnosticNamesOnlyRecordedOpens(): void
    {
        $logger = new SemanticLogger(1);
        $kept = $logger->open(new FakeContext('kept'));
        $logger->open(new FakeContext('lost'));

        $log = $logger->flush()->toArray()['log'];

        $this->assertSame([$kept], $log[1]['context']['unclosedIds'] ?? null);
        $this->assertSame(CoreSchema::TRUNCATED_TYPE, $log[2]['type']);
    }

    public function testSnapshotPreviewsTheMarkerWithoutEndingTheSession(): void
    {
        $logger = new SemanticLogger(1);
        $logger->event(new FakeContext('kept'));
        $logger->event(new FakeContext('lost'));

        $preview = $logger->toArray()['log'];
        $flushed = $logger->flush()->toArray()['log'];

        $this->assertSame($flushed, $preview);
    }

    public function testFlushStartsAFreshBudget(): void
    {
        $logger = new SemanticLogger(1);
        $logger->event(new FakeContext('first'));
        $logger->event(new FakeContext('dropped'));
        $logger->flush();

        $logger->event(new FakeContext('second session'));
        $log = $logger->flush()->toArray()['log'];

        $this->assertCount(1, $log);
        $this->assertSame('second session', $log[0]['context']['message']);
    }

    public function testDefaultLimitStopsAtOneThousand(): void
    {
        $logger = new SemanticLogger();
        for ($i = 0; $i < SemanticLogger::DEFAULT_MAX_RECORDS + 5; $i++) {
            $logger->event(new FakeContext('row', $i));
        }

        $log = $logger->flush()->toArray()['log'];

        $this->assertSame(SemanticLogger::DEFAULT_MAX_RECORDS + 1, count($log));
        $this->assertSame(['limit' => 1000, 'dropped' => 5, 'firstDroppedType' => FakeContext::TYPE], $log[1000]['context']);
    }
}
