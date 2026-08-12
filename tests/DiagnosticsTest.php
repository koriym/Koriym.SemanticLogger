<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_UNESCAPED_SLASHES;

/**
 * The single behavior of the logger: recording failures and protocol misuse
 * become core-owned diagnostic entries — the logger never throws.
 *
 * @psalm-import-type DiagnosticData from Types
 */
final class DiagnosticsTest extends TestCase
{
    private SemanticLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new SemanticLogger();
    }

    public function testSerializationFailureOnOpenPreservesTopology(): void
    {
        // A bad context becomes a placeholder open; children still attach to it,
        // so the tree keeps the real dependency structure.
        $brokenId = $this->logger->open(new UnserializableContext());
        $childId = $this->logger->open(new FakeContext('child', 1));
        $this->logger->close(new FakeContext('child done', 2), $childId);
        $this->logger->close(new FakeContext('broken done', 3), $brokenId);

        $log = $this->logger->flush();

        $this->assertCount(1, $log->open);
        $root = $log->open[0];
        $this->assertSame(CoreSchema::INVALID_CONTEXT_TYPE, $root->type);
        $this->assertSame(CoreSchema::INVALID_CONTEXT_URL, $root->schemaUrl);
        $this->assertSame('open', $root->context['operation']);
        $errors = $root->context['errors'] ?? null;
        $this->assertIsArray($errors);
        $error = $errors[0] ?? null;
        $this->assertIsArray($error);
        $this->assertSame('context_serialization_failed', $error['kind']);
        $this->assertSame('unserializable_context', $root->context['originalType']);
        $this->assertSame('https://example.com/schemas/unserializable.json', $root->context['originalSchemaUrl']);

        // Topology: the child is nested under the placeholder, not re-parented.
        $this->assertCount(1, $root->open);
        $this->assertSame($childId, $root->open[0]->id);

        // The failure is also recorded as a diagnostic event.
        $diagnostics = $this->diagnosticsOf($log);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('context_serialization_failed', $diagnostics[0]['kind']);
        $this->assertSame($brokenId, $diagnostics[0]['relatedId'] ?? null);
        $this->assertArrayHasKey('exceptionClass', $diagnostics[0]);
    }

    public function testSerializationFailureLosesOnlyThatEntry(): void
    {
        // Loss radius: one bad context costs exactly one entry; the rest of the
        // session is intact.
        $this->logger->event(new FakeContext('before', 1));
        $this->logger->event(new UnserializableContext());
        $this->logger->event(new FakeContext('after', 2));

        $log = $this->logger->flush();

        $userEvents = $this->userEventsOf($log);
        $this->assertCount(3, $userEvents);
        $this->assertSame('example_event', $userEvents[0]->type);
        $this->assertSame('before', $userEvents[0]->context['message']);
        $this->assertSame(CoreSchema::INVALID_CONTEXT_TYPE, $userEvents[1]->type);
        $this->assertSame('event', $userEvents[1]->context['operation']);
        $this->assertSame('example_event', $userEvents[2]->type);
        $this->assertSame('after', $userEvents[2]->context['message']);

        $diagnostics = $this->diagnosticsOf($log);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('context_serialization_failed', $diagnostics[0]['kind']);
    }

    public function testSerializationFailureOnCloseRecordsPlaceholderClose(): void
    {
        $openId = $this->logger->open(new FakeContext('work', 1));
        $this->logger->close(new UnserializableContext(), $openId);

        $log = $this->logger->flush();

        $this->assertCount(1, $log->open);
        $this->assertSame('example_event', $log->open[0]->type);
        $this->assertCount(1, $log->close);
        $this->assertSame(CoreSchema::INVALID_CONTEXT_TYPE, $log->close[0]->type);
        $this->assertSame('close', $log->close[0]->context['operation']);

        $diagnostics = $this->diagnosticsOf($log);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('context_serialization_failed', $diagnostics[0]['kind']);
        $this->assertSame($openId, $diagnostics[0]['relatedId'] ?? null);
    }

    public function testDataDependentSerializationFailure(): void
    {
        // Correct code, bad runtime data: json_encode cannot represent INF.
        $this->logger->event(new InfContext());

        $log = $this->logger->flush();

        $userEvents = $this->userEventsOf($log);
        $this->assertCount(1, $userEvents);
        $this->assertSame(CoreSchema::INVALID_CONTEXT_TYPE, $userEvents[0]->type);
        $this->assertSame('inf_context', $userEvents[0]->context['originalType']);

        $diagnostics = $this->diagnosticsOf($log);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('context_serialization_failed', $diagnostics[0]['kind']);
    }

    public function testCloseWithoutOpenRecordsDiagnosticAndKeepsState(): void
    {
        $this->logger->close(new FakeContext('rejected', 1), 'missing_open_1');

        // The session continues unaffected.
        $openId = $this->logger->open(new FakeContext('work', 2));
        $this->logger->close(new FakeContext('work done', 3), $openId);

        $log = $this->logger->flush();

        $diagnostics = $this->diagnosticsOf($log);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('close_without_open', $diagnostics[0]['kind']);
        $this->assertSame('missing_open_1', $diagnostics[0]['relatedId'] ?? null);
        $discarded = $diagnostics[0]['discardedContext'] ?? null;
        $this->assertIsArray($discarded);
        $this->assertSame('rejected', $discarded['message']);

        // The real operation is intact.
        $this->assertCount(1, $log->open);
        $this->assertSame('work', $log->open[0]->context['message']);
    }

    public function testCloseIdMismatchRecordsDiagnosticAndKeepsStack(): void
    {
        $outerId = $this->logger->open(new FakeContext('outer', 1));
        $innerId = $this->logger->open(new FakeContext('inner', 2));

        // LIFO violation: closing the outer while the inner is on top.
        $this->logger->close(new FakeContext('rejected', 3), $outerId);

        // The stack is not guess-mutated: correct closes still work.
        $this->logger->close(new FakeContext('inner done', 4), $innerId);
        $this->logger->close(new FakeContext('outer done', 5), $outerId);

        $log = $this->logger->flush();

        $diagnostics = $this->diagnosticsOf($log);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('close_id_mismatch', $diagnostics[0]['kind']);
        $this->assertSame($outerId, $diagnostics[0]['relatedId'] ?? null);
        $discarded = $diagnostics[0]['discardedContext'] ?? null;
        $this->assertIsArray($discarded);
        $this->assertSame('rejected', $discarded['message']);

        // The tree is the real one: inner nested under outer, both closed.
        $this->assertCount(1, $log->open);
        $this->assertSame($outerId, $log->open[0]->id);
        $this->assertCount(1, $log->open[0]->open);
        $this->assertSame($innerId, $log->open[0]->open[0]->id);
    }

    public function testUnclosedAtFlushRecordsDiagnosticAndReturnsLiveLog(): void
    {
        $outerId = $this->logger->open(new FakeContext('outer', 1));
        $innerId = $this->logger->open(new FakeContext('inner', 2));
        $this->logger->event(new FakeContext('note', 3));

        $log = $this->logger->flush();

        // Live log: the unclosed operations are still in the tree.
        $this->assertCount(1, $log->open);
        $this->assertSame($outerId, $log->open[0]->id);
        $this->assertCount(1, $log->open[0]->open);
        $this->assertSame($innerId, $log->open[0]->open[0]->id);

        $diagnostics = $this->diagnosticsOf($log);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('unclosed_at_flush', $diagnostics[0]['kind']);
        $this->assertSame([$outerId, $innerId], $diagnostics[0]['unclosedIds'] ?? null);

        // flush() always resets: the next session starts clean.
        $next = $this->logger->flush();
        $this->assertSame([], $next->open);
        $this->assertSame([], $next->events);
    }

    public function testEmptyFlushReturnsEmptyLog(): void
    {
        $log = $this->logger->flush();

        $this->assertSame(CoreSchema::LOG_URL, $log->schemaUrl);
        $this->assertSame([], $log->open);
        $this->assertSame([], $log->close);
        $this->assertSame([], $log->events);

        $array = $log->toArray();
        $this->assertSame(CoreSchema::LOG_URL, $array['$schema']);
        $this->assertSame([], $array['open']);
    }

    public function testEmptyLogValidatesAgainstSchema(): void
    {
        // The envelope schema itself accepts an empty session: behavior and
        // schema changed together.
        $log = $this->logger->flush();
        $file = tempnam(sys_get_temp_dir(), 'semantic_log_test_');
        $this->assertNotFalse($file);
        file_put_contents($file, json_encode($log, JSON_UNESCAPED_SLASHES));

        try {
            (new SemanticLogValidator())->validate($file, dirname(__DIR__) . '/demo/schemas');
            $this->addToAssertionCount(1); // validate() did not throw
        } finally {
            unlink($file);
        }
    }

    public function testToArrayIsNonDestructiveSnapshot(): void
    {
        $openId = $this->logger->open(new FakeContext('work', 1));

        // Snapshot of a live session includes the unclosed diagnostic preview.
        $snapshot = $this->logger->toArray();
        $this->assertSame(CoreSchema::LOG_URL, $snapshot['$schema']);
        $this->assertCount(1, $snapshot['open']);

        // The session survives the snapshot.
        $this->logger->close(new FakeContext('work done', 2), $openId);
        $log = $this->logger->flush();
        $this->assertCount(1, $log->open);
        $this->assertSame([], $this->diagnosticsOf($log));
    }

    public function testToArrayWithoutSessionReturnsEmptyShape(): void
    {
        $snapshot = $this->logger->toArray();

        $this->assertSame(CoreSchema::LOG_URL, $snapshot['$schema']);
        $this->assertSame([], $snapshot['open']);
    }

    /** @return list<DiagnosticData> */
    private function diagnosticsOf(LogJson $log): array
    {
        $diagnostics = [];
        foreach ($log->events as $event) {
            if ($event->type === CoreSchema::DIAGNOSTIC_TYPE) {
                /** @var DiagnosticData $context */
                $context = $event->context;
                $diagnostics[] = $context;
            }
        }

        return $diagnostics;
    }

    /** @return list<EventEntry> */
    private function userEventsOf(LogJson $log): array
    {
        $events = [];
        foreach ($log->events as $event) {
            if ($event->type !== CoreSchema::DIAGNOSTIC_TYPE) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
