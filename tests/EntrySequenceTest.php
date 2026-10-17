<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_map;
use function assert;
use function dirname;
use function file_put_contents;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * The record-order sequence (`seq`).
 *
 * The pinned case is the one that motivated the field: a scope that ran
 * purge → nested GET → purge. The public document keeps the scope's own events
 * in `events` and the nested scope in `open`, so structural order shows both
 * purges before the GET — the opposite of what happened for the second purge.
 */
final class EntrySequenceTest extends TestCase
{
    private string $fixtureDirectory = '';
    private string $schemaDirectory = '';
    private string $logFile = '';

    #[Override]
    protected function setUp(): void
    {
        $this->fixtureDirectory = sys_get_temp_dir() . '/semantic-log-seq-' . uniqid('', true);
        $this->schemaDirectory = $this->fixtureDirectory . '/schemas';
        $this->logFile = $this->fixtureDirectory . '/log.json';
        mkdir($this->schemaDirectory, 0777, true);
        file_put_contents(
            $this->schemaDirectory . '/example.json',
            '{"type":"object","additionalProperties":true}',
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ([$this->schemaDirectory . '/example.json', $this->logFile] as $file) {
            if (! is_dir($this->fixtureDirectory)) {
                continue;
            }

            @unlink($file);
        }

        @rmdir($this->schemaDirectory);
        @rmdir($this->fixtureDirectory);
    }

    /** The pin: a scope event recorded after a nested scope is ordered after it. */
    public function testEventRecordedAfterNestedScopeIsOrderedAfterIt(): void
    {
        $document = $this->purgeGetPurgeDocument();
        $command = $this->onlyOpen($document);
        $events = $this->entryList($command, 'events');
        $nested = $this->entryList($command, 'open');

        $firstPurge = $this->seqOf($events[0]);
        $secondPurge = $this->seqOf($events[1]);
        $nestedGet = $this->seqOf($nested[0]);

        // Structural order alone is misleading: both purges precede the nested open.
        $this->assertSame(['purge one', 'purge two'], array_map(
            fn (array $event): mixed => $this->entryAt($event, 'context')['message'] ?? null,
            $events,
        ));

        // seq restores what happened: purge, GET, purge.
        $this->assertLessThan($nestedGet, $firstPurge);
        $this->assertGreaterThan($nestedGet, $secondPurge);
    }

    /** The nested scope's close bounds it: the second purge happened after the GET finished. */
    public function testNestedScopeCloseIsOrderedBeforeAFollowingEvent(): void
    {
        $document = $this->purgeGetPurgeDocument();
        $command = $this->onlyOpen($document);
        $nested = $this->entryList($command, 'open');
        $nestedClose = $this->entryAt($nested[0], 'close');

        $this->assertGreaterThan($this->seqOf($nested[0]), $this->seqOf($nestedClose));
        $this->assertGreaterThan($this->seqOf($nestedClose), $this->seqOf($this->entryList($command, 'events')[1]));
    }

    public function testSequenceStartsAtOneAndIsGapFreeAcrossEveryEntryKind(): void
    {
        $logger = new SemanticLogger();
        $outer = $logger->open(new FakeContext('outer'));
        $logger->event(new FakeContext('event'));
        $inner = $logger->open(new FakeContext('inner'));
        $logger->close(new FakeContext('inner done'), $inner);
        $logger->close(new FakeContext('outer done'), $outer);

        $log = $logger->flush();
        $command = $this->onlyOpen($log->toArray());
        $nested = $this->entryList($command, 'open');
        $nestedClose = $this->entryAt($nested[0], 'close');
        $outerClose = $this->entryAt($command, 'close');

        $this->assertSame(1, $this->seqOf($command));
        $this->assertSame(2, $this->seqOf($this->entryList($command, 'events')[0]));
        $this->assertSame(3, $this->seqOf($nested[0]));
        $this->assertSame(4, $this->seqOf($nestedClose));
        $this->assertSame(5, $this->seqOf($outerClose));
    }

    /** A close-time diagnostic sits inside the scope being closed, so the close must come after it. */
    public function testCloseTimeDiagnosticIsOrderedBeforeTheClose(): void
    {
        $logger = new SemanticLogger();
        $operation = $logger->open(new FakeContext('operation'));
        $logger->close(new UnserializableContext(), $operation);

        $document = $logger->flush()->toArray();
        $open = $this->onlyOpen($document);
        $diagnostic = $this->entryList($open, 'events')[0];

        $this->assertSame(2, $this->seqOf($diagnostic));
        $this->assertSame(3, $this->seqOf($this->entryAt($open, 'close')));
        $this->assertSame([], $this->check($document));
    }

    /** Every diagnostic the logger can record must leave a sequence its own validator accepts. */
    public function testEveryDiagnosticPathYieldsAConsistentSequence(): void
    {
        $logger = new SemanticLogger();
        $outer = $logger->open(new UnserializableContext());
        $logger->event(new UnserializableContext());
        $inner = $logger->open(new FakeContext('inner'));
        $logger->close(new FakeContext('wrong order'), $outer);
        $logger->close(new FakeContext('inner done'), $inner);
        $logger->close(new FakeContext('outer done'), $outer);
        $logger->close(new FakeContext('nothing open'), $outer);

        $this->assertSame([], $this->check($logger->flush()->toArray()));

        $logger->open(new FakeContext('left open'));

        $this->assertSame([], $this->check($logger->flush()->toArray()));
    }

    public function testSequenceRestartsWithTheNextSession(): void
    {
        $logger = new SemanticLogger();
        $first = $logger->open(new FakeContext('first'));
        $logger->close(new FakeContext('first done'), $first);
        $logger->flush();

        $second = $logger->open(new FakeContext('second'));
        $logger->close(new FakeContext('second done'), $second);

        $this->assertSame(1, $this->seqOf($this->onlyOpen($logger->flush()->toArray())));
    }

    /** A snapshot is a read: previewing the unclosed diagnostic must not consume a position. */
    public function testSnapshotPreviewDoesNotConsumeSequencePositions(): void
    {
        $logger = new SemanticLogger();
        $logger->open(new FakeContext('unclosed'));

        $first = $logger->toArray();
        $second = $logger->toArray();

        $this->assertSame($this->diagnosticSeq($first), $this->diagnosticSeq($second));

        // The recorded diagnostic at flush takes the position the previews only claimed.
        $flushed = $logger->flush()->toArray();
        $this->assertSame($this->diagnosticSeq($first), $this->diagnosticSeq($flushed));
    }

    public function testLoggerOutputValidatesAgainstThePublishedEnvelopeSchema(): void
    {
        $document = $this->purgeGetPurgeDocument();
        file_put_contents($this->logFile, json_encode($document, JSON_THROW_ON_ERROR));

        $this->expectOutputRegex('/All contexts validate successfully/');
        (new SemanticLogValidator())->validate($this->logFile, $this->schemaDirectory, true);
    }

    /** Additive: a document written before `seq` existed still validates. */
    public function testLegacyDocumentWithoutSequenceStillValidates(): void
    {
        $demo = dirname(__DIR__) . '/demo/simple-demo.json';

        $this->expectOutputRegex('/All contexts validate successfully/');
        (new SemanticLogValidator())->validate($demo, dirname(__DIR__) . '/demo/schemas');
    }

    public function testCheckerAcceptsDocumentWithoutSequence(): void
    {
        $this->assertSame([], $this->check([
            'open' => [
                [
                    'id' => 'a_1',
                    'type' => 'a',
                    'schemaUrl' => './schemas/a.json',
                    'context' => [],
                    'events' => [['id' => 'b_1', 'type' => 'b', 'schemaUrl' => './schemas/b.json', 'context' => []]],
                ],
            ],
        ]));
    }

    public function testCheckerRejectsPartialSequence(): void
    {
        $violations = $this->check([
            'open' => [
                [
                    'id' => 'a_1',
                    'type' => 'a',
                    'schemaUrl' => './schemas/a.json',
                    'context' => [],
                    'seq' => 1,
                    'events' => [['id' => 'b_1', 'type' => 'b', 'schemaUrl' => './schemas/b.json', 'context' => []]],
                ],
            ],
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('for every entry or for none', $violations[0]);
    }

    public function testCheckerRejectsDuplicateSequence(): void
    {
        $violations = $this->check([
            'open' => [
                [
                    'id' => 'a_1',
                    'type' => 'a',
                    'schemaUrl' => './schemas/a.json',
                    'context' => [],
                    'seq' => 1,
                    'events' => [
                        ['id' => 'b_1', 'type' => 'b', 'schemaUrl' => './schemas/b.json', 'context' => [], 'seq' => 2],
                        ['id' => 'b_2', 'type' => 'b', 'schemaUrl' => './schemas/b.json', 'context' => [], 'seq' => 2],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Duplicate seq 2', $violations[0]);
    }

    public function testCheckerRejectsChildRecordedBeforeItsEnclosingOpen(): void
    {
        $violations = $this->check([
            'open' => [
                [
                    'id' => 'a_1',
                    'type' => 'a',
                    'schemaUrl' => './schemas/a.json',
                    'context' => [],
                    'seq' => 5,
                    'open' => [['id' => 'b_1', 'type' => 'b', 'schemaUrl' => './schemas/b.json', 'context' => [], 'seq' => 2]],
                ],
            ],
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('must be greater than the enclosing open seq 5', $violations[0]);
    }

    public function testCheckerRejectsSiblingsOutOfRecordOrder(): void
    {
        $violations = $this->check([
            'open' => [
                [
                    'id' => 'a_1',
                    'type' => 'a',
                    'schemaUrl' => './schemas/a.json',
                    'context' => [],
                    'seq' => 1,
                    'events' => [
                        ['id' => 'b_1', 'type' => 'b', 'schemaUrl' => './schemas/b.json', 'context' => [], 'seq' => 5],
                        ['id' => 'b_2', 'type' => 'b', 'schemaUrl' => './schemas/b.json', 'context' => [], 'seq' => 3],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('must be greater than every seq under the preceding sibling open[0].events[0]', $violations[0]);
    }

    /** A sibling scope starts only after the previous one closed. */
    public function testCheckerRejectsSiblingRecordedBeforeThePrecedingSiblingClosed(): void
    {
        $violations = $this->check([
            'open' => [
                [
                    'id' => 'a_1',
                    'type' => 'a',
                    'schemaUrl' => './schemas/a.json',
                    'context' => [],
                    'seq' => 1,
                    'open' => [
                        [
                            'id' => 'b_1',
                            'type' => 'b',
                            'schemaUrl' => './schemas/b.json',
                            'context' => [],
                            'seq' => 2,
                            'close' => ['id' => 'c_1', 'type' => 'c', 'schemaUrl' => './schemas/c.json', 'context' => [], 'seq' => 6],
                        ],
                        ['id' => 'b_2', 'type' => 'b', 'schemaUrl' => './schemas/b.json', 'context' => [], 'seq' => 4],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('seq 4 must be greater than every seq under the preceding sibling open[0].open[0] (found 6)', $violations[0]);
    }

    /** Nested orphan closes (internal shape) count toward the preceding sibling's extent. */
    public function testCheckerSeesNestedOrphanClosesWhenOrderingSiblings(): void
    {
        $close = static fn (string $id, int $seq, array $nested = []): array => [
            'id' => $id,
            'type' => 'example',
            'schemaUrl' => './schemas/example.json',
            'context' => [],
            'openId' => 'ghost',
            'seq' => $seq,
            ...($nested === [] ? [] : ['close' => $nested]),
        ];
        $document = [
            'close' => [
                $close('close_1', 2, [$close('close_2', 5)]),
                $close('close_3', 4),
            ],
        ];

        $violations = $this->check($document);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('[close[1]] seq 4 must be greater than every seq under the preceding sibling close[0] (found 5)', $violations[0]);
    }

    public function testCheckerRejectsCloseRecordedBeforeSomethingNestedInside(): void
    {
        $violations = $this->check([
            'open' => [
                [
                    'id' => 'a_1',
                    'type' => 'a',
                    'schemaUrl' => './schemas/a.json',
                    'context' => [],
                    'seq' => 1,
                    'events' => [['id' => 'b_1', 'type' => 'b', 'schemaUrl' => './schemas/b.json', 'context' => [], 'seq' => 9]],
                    'close' => ['id' => 'c_1', 'type' => 'c', 'schemaUrl' => './schemas/c.json', 'context' => [], 'seq' => 4],
                ],
            ],
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('must be greater than every seq nested under', $violations[0]);
    }

    public function testCheckerRejectsCloseRecordedBeforeItsOwnOpen(): void
    {
        $violations = $this->check([
            'open' => [
                [
                    'id' => 'a_1',
                    'type' => 'a',
                    'schemaUrl' => './schemas/a.json',
                    'context' => [],
                    'seq' => 7,
                    'close' => ['id' => 'c_1', 'type' => 'c', 'schemaUrl' => './schemas/c.json', 'context' => [], 'seq' => 2],
                ],
            ],
        ]);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Close seq 2 must be greater than its open seq 7', $violations[0]);
    }

    public function testValidatorRejectsAnInconsistentSequence(): void
    {
        $document = $this->purgeGetPurgeDocument();
        $open = $document['open'];
        assert(is_array($open));
        $command = $open[0];
        assert(is_array($command));
        $events = $command['events'];
        assert(is_array($events));
        $second = $events[1];
        assert(is_array($second));
        // Claim the second purge happened first: the reader would conclude purge, purge, GET.
        $second['seq'] = 1;
        $events[1] = $second;
        $command['events'] = $events;
        $open[0] = $command;
        $document['open'] = $open;
        file_put_contents($this->logFile, json_encode($document, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectOutputRegex('/Duplicate seq 1/');
        (new SemanticLogValidator())->validate($this->logFile, $this->schemaDirectory);
    }

    /**
     * command scope that ran purge → refresh(GET) → purge.
     *
     * @return array<string, mixed>
     */
    private function purgeGetPurgeDocument(): array
    {
        $logger = new SemanticLogger();
        $command = $logger->open(new FakeContext('command'));
        $logger->event(new FakeContext('purge one'));
        $get = $logger->open(new FakeContext('refresh get'));
        $logger->close(new FakeContext('get done'), $get);
        $logger->event(new FakeContext('purge two'));
        $logger->close(new FakeContext('command done'), $command);

        /** @var array<string, mixed> $document */
        $document = json_decode(json_encode($logger->flush()->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function onlyOpen(array $document): array
    {
        $open = $document['open'] ?? null;
        assert(is_array($open));
        $entry = $open[0] ?? null;
        assert(is_array($entry));

        /** @var array<string, mixed> $entry */
        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function entryAt(array $entry, string $key): array
    {
        $value = $entry[$key] ?? null;
        assert(is_array($value));

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<array<string, mixed>>
     */
    private function entryList(array $entry, string $key): array
    {
        $list = $entry[$key] ?? null;
        assert(is_array($list));

        /** @var list<array<string, mixed>> $list */
        return $list;
    }

    /** @param array<string, mixed> $entry */
    private function seqOf(array $entry): int
    {
        $seq = $entry['seq'] ?? null;
        $this->assertIsInt($seq, 'every logger-produced entry carries seq');

        return $seq;
    }

    /** @param array<string, mixed> $document */
    private function diagnosticSeq(array $document): int
    {
        $events = $this->entryList($document, 'events');
        $seqs = array_column($events, 'seq');
        $this->assertCount(1, $seqs);

        /** @var int $seq */
        $seq = $seqs[0];

        return $seq;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function check(array $document): array
    {
        /** @var object $decoded */
        $decoded = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        return (new EntrySequenceChecker())->check($decoded);
    }
}
