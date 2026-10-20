<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use PHPUnit\Framework\TestCase;

use function array_column;
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
 * @psalm-import-type PublicEntry from Types
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

        $entries = $this->entriesOf($this->logger->flush());

        $this->assertCount(1, $entries);
        $root = $entries[0];
        $this->assertSame('open', $root['kind']);
        $this->assertSame(CoreSchema::INVALID_CONTEXT_TYPE, $root['type']);
        $this->assertSame(CoreSchema::INVALID_CONTEXT_URL, $root['schemaUrl']);
        $context = $root['context'];
        $this->assertSame('open', $context['operation']);
        $errors = $context['errors'] ?? null;
        $this->assertIsArray($errors);
        $error = $errors[0] ?? null;
        $this->assertIsArray($error);
        $this->assertSame('context_serialization_failed', $error['code']);
        $this->assertSame('unserializable_context', $context['originalType']);
        $this->assertSame('https://example.com/schemas/unserializable.json', $context['originalSchemaUrl']);

        // The failure is recorded as a diagnostic inside the placeholder, ahead of its child.
        $children = $this->childrenOf($root);
        $this->assertCount(2, $children);
        $diagnostics = $this->diagnosticsIn($children);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('context_serialization_failed', $diagnostics[0]['code']);
        $this->assertSame($brokenId, $diagnostics[0]['relatedId'] ?? null);
        $this->assertArrayHasKey('exceptionClass', $diagnostics[0]);

        // Topology: the child is nested under the placeholder, not re-parented.
        $this->assertSame('open', $children[1]['kind']);
        $this->assertSame($childId, $children[1]['id']);
        $this->assertSame('child done', $this->closeOf($children[1])['context']['message']);
        $this->assertSame('broken done', $this->closeOf($root)['context']['message']);
    }

    public function testSerializationFailureLosesOnlyThatEntry(): void
    {
        // Loss radius: one bad context costs exactly one entry; the rest of the
        // session is intact.
        $this->logger->event(new FakeContext('before', 1));
        $this->logger->event(new UnserializableContext());
        $this->logger->event(new FakeContext('after', 2));

        $entries = $this->entriesOf($this->logger->flush());

        // Placeholder event, then the diagnostic recorded in the same scope.
        $this->assertCount(4, $entries);
        $this->assertSame(['event', 'event', 'event', 'event'], array_column($entries, 'kind'));
        $userEvents = $this->userEventsIn($entries);
        $this->assertCount(3, $userEvents);
        $this->assertSame('example_event', $userEvents[0]['type']);
        $this->assertSame('before', $userEvents[0]['context']['message']);
        $this->assertSame(CoreSchema::INVALID_CONTEXT_TYPE, $userEvents[1]['type']);
        $this->assertSame('event', $userEvents[1]['context']['operation']);
        $this->assertSame('example_event', $userEvents[2]['type']);
        $this->assertSame('after', $userEvents[2]['context']['message']);

        $diagnostics = $this->diagnosticsIn($entries);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('context_serialization_failed', $diagnostics[0]['code']);
        $this->assertSame($userEvents[1]['id'], $diagnostics[0]['relatedId'] ?? null);
    }

    public function testSerializationFailureOnEventIsRecordedInTheEnclosingScope(): void
    {
        $openId = $this->logger->open(new FakeContext('work', 1));
        $this->logger->event(new UnserializableContext());
        $this->logger->close(new FakeContext('work done', 2), $openId);

        $entries = $this->entriesOf($this->logger->flush());

        $this->assertCount(1, $entries);
        $children = $this->childrenOf($entries[0]);
        $this->assertCount(2, $children);
        $this->assertSame(CoreSchema::INVALID_CONTEXT_TYPE, $children[0]['type']);
        $this->assertCount(1, $this->diagnosticsIn($children));
        $this->assertSame([], $this->diagnosticsIn($entries));
    }

    public function testSerializationFailureOnCloseRecordsPlaceholderClose(): void
    {
        $openId = $this->logger->open(new FakeContext('work', 1));
        $this->logger->close(new UnserializableContext(), $openId);

        $log = $this->logger->flush();
        $entries = $this->entriesOf($log);

        $this->assertCount(1, $entries);
        $this->assertSame('example_event', $entries[0]['type']);
        $close = $this->closeOf($entries[0]);
        $this->assertSame(CoreSchema::INVALID_CONTEXT_TYPE, $close['type']);
        $this->assertSame('close', $close['context']['operation']);
        $this->assertArrayNotHasKey('openId', $close);
        $this->assertArrayNotHasKey('close', $log->toArray());

        $diagnostics = $this->diagnosticsIn($this->childrenOf($entries[0]));
        $this->assertCount(1, $diagnostics);
        $this->assertSame('context_serialization_failed', $diagnostics[0]['code']);
        $this->assertSame($openId, $diagnostics[0]['relatedId'] ?? null);
    }

    public function testCloseTimeDiagnosticIsChildOfTheClosedOpen(): void
    {
        // The diagnostic is appended to children before the close is attached,
        // so the open keeps its close and the diagnostic follows earlier children.
        $outerId = $this->logger->open(new FakeContext('outer', 1));
        $innerId = $this->logger->open(new FakeContext('inner', 2));
        $this->logger->event(new FakeContext('note', 3));
        $this->logger->close(new UnserializableContext(), $innerId);
        $this->logger->close(new FakeContext('outer done', 4), $outerId);

        $entries = $this->entriesOf($this->logger->flush());

        $this->assertCount(1, $entries);
        $outer = $entries[0];
        $this->assertSame([], $this->diagnosticsIn($this->childrenOf($outer)));
        $this->assertSame('outer done', $this->closeOf($outer)['context']['message']);

        $outerChildren = $this->childrenOf($outer);
        $this->assertCount(1, $outerChildren);
        $inner = $outerChildren[0];
        $this->assertSame($innerId, $inner['id']);

        $innerChildren = $this->childrenOf($inner);
        $this->assertSame(['event', 'event'], array_column($innerChildren, 'kind'));
        $this->assertSame('note', $innerChildren[0]['context']['message']);
        $this->assertSame(CoreSchema::DIAGNOSTIC_TYPE, $innerChildren[1]['type']);
        $diagnostics = $this->diagnosticsIn($innerChildren);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('context_serialization_failed', $diagnostics[0]['code']);
        $this->assertSame($innerId, $diagnostics[0]['relatedId'] ?? null);

        $this->assertSame(CoreSchema::INVALID_CONTEXT_TYPE, $this->closeOf($inner)['type']);
    }

    public function testDataDependentSerializationFailure(): void
    {
        // Correct code, bad runtime data: json_encode cannot represent INF.
        $this->logger->event(new InfContext());

        $entries = $this->entriesOf($this->logger->flush());

        $userEvents = $this->userEventsIn($entries);
        $this->assertCount(1, $userEvents);
        $this->assertSame(CoreSchema::INVALID_CONTEXT_TYPE, $userEvents[0]['type']);
        $this->assertSame('inf_context', $userEvents[0]['context']['originalType']);

        $diagnostics = $this->diagnosticsIn($entries);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('context_serialization_failed', $diagnostics[0]['code']);
    }

    public function testCloseWithoutOpenRecordsDiagnosticAndKeepsState(): void
    {
        $this->logger->close(new FakeContext('rejected', 1), 'missing_open_1');

        // The session continues unaffected.
        $openId = $this->logger->open(new FakeContext('work', 2));
        $this->logger->close(new FakeContext('work done', 3), $openId);

        $log = $this->logger->flush();
        $entries = $this->entriesOf($log);

        // No open existed, so the diagnostic sits at the root, before the real operation.
        $this->assertCount(2, $entries);
        $this->assertSame('event', $entries[0]['kind']);
        $diagnostics = $this->diagnosticsIn($entries);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('close_without_open', $diagnostics[0]['code']);
        $this->assertSame('missing_open_1', $diagnostics[0]['relatedId'] ?? null);
        $discarded = $diagnostics[0]['discardedContext'] ?? null;
        $this->assertIsArray($discarded);
        $this->assertSame('rejected', $discarded['message']);

        // The real operation is intact and the rejected close is not an orphan close.
        $this->assertSame('open', $entries[1]['kind']);
        $this->assertSame('work', $entries[1]['context']['message']);
        $this->assertSame('work done', $this->closeOf($entries[1])['context']['message']);
        $this->assertArrayNotHasKey('close', $log->toArray());
    }

    public function testDoubleCloseRecordsDiagnosticAndKeepsFirstClose(): void
    {
        $openId = $this->logger->open(new FakeContext('work', 1));
        $this->logger->close(new FakeContext('first', 2), $openId);
        $this->logger->close(new FakeContext('second', 3), $openId);

        $log = $this->logger->flush();
        $entries = $this->entriesOf($log);

        $this->assertCount(2, $entries);
        $this->assertSame('first', $this->closeOf($entries[0])['context']['message']);
        $diagnostics = $this->diagnosticsIn($entries);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('close_without_open', $diagnostics[0]['code']);
        $this->assertSame($openId, $diagnostics[0]['relatedId'] ?? null);
        $this->assertArrayNotHasKey('close', $log->toArray());
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

        $entries = $this->entriesOf($this->logger->flush());

        // The tree is the real one: inner nested under outer, both closed.
        $this->assertCount(1, $entries);
        $outer = $entries[0];
        $this->assertSame($outerId, $outer['id']);
        $this->assertSame('outer done', $this->closeOf($outer)['context']['message']);
        $outerChildren = $this->childrenOf($outer);
        $this->assertCount(1, $outerChildren);
        $inner = $outerChildren[0];
        $this->assertSame($innerId, $inner['id']);
        $this->assertSame('inner done', $this->closeOf($inner)['context']['message']);

        // The rejection is recorded in the scope that was current: the inner open.
        $this->assertSame([], $this->diagnosticsIn($outerChildren));
        $diagnostics = $this->diagnosticsIn($this->childrenOf($inner));
        $this->assertCount(1, $diagnostics);
        $this->assertSame('close_id_mismatch', $diagnostics[0]['code']);
        $this->assertSame($outerId, $diagnostics[0]['relatedId'] ?? null);
        $discarded = $diagnostics[0]['discardedContext'] ?? null;
        $this->assertIsArray($discarded);
        $this->assertSame('rejected', $discarded['message']);
    }

    public function testUnclosedAtFlushRecordsDiagnosticAndReturnsLiveLog(): void
    {
        $outerId = $this->logger->open(new FakeContext('outer', 1));
        $innerId = $this->logger->open(new FakeContext('inner', 2));
        $this->logger->event(new FakeContext('note', 3));

        $entries = $this->entriesOf($this->logger->flush());

        // Live log: the unclosed operations are still in the tree, without a close.
        $this->assertCount(2, $entries);
        $outer = $entries[0];
        $this->assertSame($outerId, $outer['id']);
        $this->assertArrayNotHasKey('close', $outer);
        $outerChildren = $this->childrenOf($outer);
        $this->assertCount(1, $outerChildren);
        $this->assertSame($innerId, $outerChildren[0]['id']);
        $this->assertArrayNotHasKey('close', $outerChildren[0]);

        $diagnostics = $this->diagnosticsIn($entries);
        $this->assertCount(1, $diagnostics);
        $this->assertSame('unclosed_at_flush', $diagnostics[0]['code']);
        $this->assertSame([$outerId, $innerId], $diagnostics[0]['unclosedIds'] ?? null);

        // flush() always resets: the next session starts clean.
        $next = $this->logger->flush();
        $this->assertSame([], $next->records);
    }

    public function testEmptyFlushReturnsEmptyLog(): void
    {
        $log = $this->logger->flush();

        $this->assertSame(CoreSchema::LOG_URL, $log->schemaUrl);
        $this->assertSame([], $log->records);

        $this->assertSame(['$schema' => CoreSchema::LOG_URL, 'entries' => []], $log->toArray());
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
        $this->assertCount(2, $snapshot['entries']);
        $preview = $this->diagnosticsIn($snapshot['entries']);
        $this->assertCount(1, $preview);
        $this->assertSame('unclosed_at_flush', $preview[0]['code']);

        // The session survives the snapshot.
        $this->logger->close(new FakeContext('work done', 2), $openId);
        $entries = $this->entriesOf($this->logger->flush());
        $this->assertCount(1, $entries);
        $this->assertSame([], $this->diagnosticsIn($entries));
    }

    public function testToArrayWithoutSessionReturnsEmptyShape(): void
    {
        $snapshot = $this->logger->toArray();

        $this->assertSame(CoreSchema::LOG_URL, $snapshot['$schema']);
        $this->assertSame([], $snapshot['entries']);
    }

    /** @return list<PublicEntry> */
    private function entriesOf(LogJson $log): array
    {
        return $log->toArray()['entries'];
    }

    /**
     * @param array<mixed> $open
     *
     * @return list<PublicEntry>
     */
    private function childrenOf(array $open): array
    {
        $children = $open['children'] ?? null;
        $this->assertIsArray($children);
        /** @var list<PublicEntry> $children */

        return $children;
    }

    /**
     * @param array<mixed> $open
     *
     * @return array{id: string, type: string, schemaUrl: string, context: array<string, mixed>, openId?: string}
     */
    private function closeOf(array $open): array
    {
        $close = $open['close'] ?? null;
        $this->assertIsArray($close);
        /** @var array{id: string, type: string, schemaUrl: string, context: array<string, mixed>, openId?: string} $close */

        return $close;
    }

    /**
     * Diagnostics among the given sibling entries (not descendants).
     *
     * @param list<PublicEntry> $entries
     *
     * @return list<DiagnosticData>
     */
    private function diagnosticsIn(array $entries): array
    {
        $diagnostics = [];
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'event' && $entry['type'] === CoreSchema::DIAGNOSTIC_TYPE) {
                /** @var DiagnosticData $context */
                $context = $entry['context'];
                $diagnostics[] = $context;
            }
        }

        return $diagnostics;
    }

    /**
     * Non-diagnostic events among the given sibling entries.
     *
     * @param list<PublicEntry> $entries
     *
     * @return list<PublicEntry>
     */
    private function userEventsIn(array $entries): array
    {
        $events = [];
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'event' && $entry['type'] !== CoreSchema::DIAGNOSTIC_TYPE) {
                $events[] = $entry;
            }
        }

        return $events;
    }
}
