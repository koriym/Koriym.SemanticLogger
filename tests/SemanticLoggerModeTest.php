<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Koriym\SemanticLogger\Exception\InvalidContextTypeException;
use Koriym\SemanticLogger\Exception\InvalidOperationOrderException;
use Koriym\SemanticLogger\Exception\InvalidSchemaUrlException;
use Koriym\SemanticLogger\Exception\NoLogSessionException;
use Koriym\SemanticLogger\Exception\NoOpenOperationsException;
use Koriym\SemanticLogger\Exception\UnclosedLogicException;
use Override;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final class SemanticLoggerModeTest extends TestCase
{
    public function testStrictIsTheDefaultAndAcceptsEventOnlySessions(): void
    {
        $logger = new SemanticLogger();
        $logger->event(new ModeTestContext('event'));

        $array = $logger->flush()->toArray();

        $this->assertSame([], $array['open']);
        $this->assertSame('mode_context_1', $this->valueAt($array, 'events', 0, 'id'));
    }

    public function testStrictInvalidTypeThrowsWithoutAdvancingIds(): void
    {
        $logger = new SemanticLogger();

        try {
            $logger->open(new ModeInvalidTypeContext());
            $this->fail('Invalid TYPE must throw in strict mode.');
        } catch (InvalidContextTypeException) {
        }

        $openId = $logger->open(new ModeTestContext('open'));
        $this->assertSame('mode_context_1', $openId);
        $logger->close(new ModeTestContext('close'), $openId);
        $logger->flush();
    }

    public function testStrictInvalidSchemaUrlThrowsWithoutAdvancingIds(): void
    {
        $logger = new SemanticLogger();

        try {
            $logger->event(new ModeInvalidSchemaContext());
            $this->fail('Invalid SCHEMA_URL must throw in strict mode.');
        } catch (InvalidSchemaUrlException) {
        }

        $logger->event(new ModeTestContext('event'));
        $this->assertSame('mode_context_1', $this->valueAt($logger->flush()->toArray(), 'events', 0, 'id'));
    }

    public function testStrictReservedNamespaceThrows(): void
    {
        $logger = new SemanticLogger();

        $this->expectException(InvalidContextTypeException::class);
        $logger->event(new ModeReservedContext());
    }

    public function testStrictOpenSerializationFailureDoesNotAdvanceIds(): void
    {
        $logger = new SemanticLogger();

        try {
            $logger->open(new ModeThrowingContext());
            $this->fail('Serialization failure must escape strict mode.');
        } catch (ModeTestSerializationException) {
        }

        $openId = $logger->open(new ModeTestContext('open'));
        $this->assertSame('mode_context_1', $openId);
        $logger->close(new ModeTestContext('close'), $openId);
        $logger->flush();
    }

    public function testStrictEventSerializationFailureDoesNotAdvanceIds(): void
    {
        $logger = new SemanticLogger();

        try {
            $logger->event(new ModeThrowingContext());
            $this->fail('Serialization failure must escape strict mode.');
        } catch (ModeTestSerializationException) {
        }

        $logger->event(new ModeTestContext('event'));
        $this->assertSame('mode_context_1', $this->valueAt($logger->flush()->toArray(), 'events', 0, 'id'));
    }

    public function testStrictCloseWithoutOpenDoesNotAdvanceIds(): void
    {
        $logger = new SemanticLogger();

        try {
            $logger->close(new ModeTestContext('rejected close'), 'missing_1');
            $this->fail('Close without open must throw in strict mode.');
        } catch (NoOpenOperationsException) {
        }

        $logger->event(new ModeTestContext('event'));
        $this->assertSame('mode_context_1', $this->valueAt($logger->flush()->toArray(), 'events', 0, 'id'));
    }

    public function testStrictWrongCloseIdPreservesStack(): void
    {
        $logger = new SemanticLogger();
        $openId = $logger->open(new ModeTestContext('open'));

        try {
            $logger->close(new ModeTestContext('rejected close'), 'wrong_1');
            $this->fail('Wrong close id must throw in strict mode.');
        } catch (InvalidOperationOrderException) {
        }

        $logger->close(new ModeTestContext('close'), $openId);
        $array = $logger->flush()->toArray();
        $this->assertSame('mode_context_2', $this->valueAt($array, 'open', 0, 'close', 'id'));
    }

    public function testStrictCloseSerializationFailurePreservesStackAndIdCounter(): void
    {
        $logger = new SemanticLogger();
        $openId = $logger->open(new ModeTestContext('open'));

        try {
            $logger->close(new ModeThrowingContext(), $openId);
            $this->fail('Serialization failure must escape strict mode.');
        } catch (ModeTestSerializationException) {
        }

        $logger->close(new ModeTestContext('close'), $openId);
        $array = $logger->flush()->toArray();
        $this->assertSame('mode_context_2', $this->valueAt($array, 'open', 0, 'close', 'id'));
    }

    public function testStrictSessionlessFlushThrowsAndResets(): void
    {
        $logger = new SemanticLogger();

        try {
            $logger->flush();
            $this->fail('Sessionless strict flush must throw.');
        } catch (NoLogSessionException) {
        }

        $logger->event(new ModeTestContext('event'));
        $this->assertSame('mode_context_1', $this->valueAt($logger->flush()->toArray(), 'events', 0, 'id'));
    }

    public function testStrictUnclosedFlushThrowsAndResets(): void
    {
        $logger = new SemanticLogger();
        $logger->open(new ModeTestContext('first session'));

        try {
            $logger->flush();
            $this->fail('Unclosed strict flush must throw.');
        } catch (UnclosedLogicException) {
        }

        $openId = $logger->open(new ModeTestContext('second session'));
        $this->assertSame('mode_context_1', $openId);
        $logger->close(new ModeTestContext('close'), $openId);
        $logger->flush();
    }

    public function testStrictUnclosedSnapshotThrowsWithoutReset(): void
    {
        $logger = new SemanticLogger();
        $openId = $logger->open(new ModeTestContext('open'));

        try {
            $logger->toArray();
            $this->fail('Unclosed strict snapshot must throw.');
        } catch (UnclosedLogicException) {
        }

        $logger->close(new ModeTestContext('close'), $openId);
        $this->assertSame($openId, $logger->flush()->toArray()['open'][0]['id']);
    }

    public function testStrictSessionlessSnapshotThrowsWithoutStartingSession(): void
    {
        $logger = new SemanticLogger();

        try {
            $logger->toArray();
            $this->fail('Sessionless strict snapshot must throw.');
        } catch (NoLogSessionException) {
        }

        $logger->event(new ModeTestContext('event'));
        $this->assertSame('mode_context_1', $this->valueAt($logger->flush()->toArray(), 'events', 0, 'id'));
    }

    public function testTotalInvalidMetadataCommitsPlaceholderAndDiagnostics(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $logger->event(new ModeInvalidMetadataContext());

        $array = $logger->flush()->toArray();

        $this->assertSame([], $array['open']);
        $this->assertSame('semantic_logger_invalid_context', $this->valueAt($array, 'events', 0, 'type'));
        $this->assertSame('event', $this->valueAt($array, 'events', 0, 'context', 'operation'));
        $this->assertSame('Invalid-Type', $this->valueAt($array, 'events', 0, 'context', 'originalType'));
        $this->assertSame('invalid-schema', $this->valueAt($array, 'events', 0, 'context', 'originalSchemaUrl'));
        $this->assertSame('invalid_type', $this->valueAt($array, 'events', 1, 'context', 'kind'));
        $this->assertSame('invalid_schema_url', $this->valueAt($array, 'events', 2, 'context', 'kind'));
    }

    public function testTotalReservedNamespaceCommitsFallback(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $logger->event(new ModeReservedContext());

        $array = $logger->flush()->toArray();

        $this->assertSame('semantic_logger_invalid_context', $this->valueAt($array, 'events', 0, 'type'));
        $this->assertSame('semantic_logger_consumer', $this->valueAt($array, 'events', 1, 'context', 'originalType'));
    }

    public function testTotalOpenSerializationFailureReturnsProtocolValidPlaceholderId(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $openId = $logger->open(new ModeThrowingContext());
        $logger->close(new ModeTestContext('close'), $openId);

        $array = $logger->flush()->toArray();

        $this->assertSame('semantic_logger_invalid_context_1', $openId);
        $this->assertSame($openId, $array['open'][0]['id']);
        $this->assertSame('context_serialization_failed', $this->valueAt($array, 'open', 0, 'events', 0, 'context', 'kind'));
    }

    public function testTotalEventSerializationFailureCommitsPlaceholderAndDiagnostic(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $logger->event(new ModeThrowingContext());

        $events = $this->arrayAt($logger->flush()->toArray(), 'events');

        $this->assertSame('semantic_logger_invalid_context', $this->valueAt($events, 0, 'type'));
        $this->assertSame('context_serialization_failed', $this->valueAt($events, 1, 'context', 'kind'));
        $this->assertSame(ModeTestSerializationException::class, $this->valueAt($events, 1, 'context', 'exceptionClass'));
        $this->assertArrayNotHasKey('discardedContext', $this->arrayAt($events, 1, 'context'));
    }

    public function testTotalDiagnosticFreezesNestedSerializableValues(): void
    {
        $value = new class implements JsonSerializable {
            public int $calls = 0;

            #[Override]
            public function jsonSerialize(): mixed
            {
                $this->calls++;
                if ($this->calls > 1) {
                    throw new ModeTestSerializationException('Nested value serialized more than once.');
                }

                return ['stable' => true];
            }
        };
        $context = new class ($value) extends AbstractContext {
            public const TYPE = 'Invalid-Type';
            public const SCHEMA_URL = './schemas/mode-context.json';

            public function __construct(public readonly JsonSerializable $value)
            {
            }
        };
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $logger->event($context);

        $json = json_encode($logger->flush()->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"stable":true', $json);
        $this->assertSame(1, $value->calls);
    }

    public function testTotalCloseWithoutOpenRecordsEventOnlyDiagnostic(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $logger->close(new ModeTestContext('rejected close'), 'missing_1');

        $array = $logger->flush()->toArray();

        $this->assertSame([], $array['open']);
        $this->assertSame('close_without_open', $this->valueAt($array, 'events', 0, 'context', 'kind'));
        $this->assertSame('missing_1', $this->valueAt($array, 'events', 0, 'context', 'relatedId'));
        $this->assertSame(['message' => 'rejected close'], $this->valueAt($array, 'events', 0, 'context', 'discardedContext'));
    }

    public function testTotalWrongCloseIdPreservesOpenAndFlushReportsItUnclosed(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $openId = $logger->open(new ModeTestContext('open'));
        $logger->close(new ModeTestContext('rejected close'), 'wrong_1');

        $array = $logger->flush()->toArray();

        $this->assertSame($openId, $array['open'][0]['id']);
        $this->assertArrayNotHasKey('close', $this->arrayAt($array, 'open', 0));
        $this->assertSame('close_id_mismatch', $this->valueAt($array, 'open', 0, 'events', 0, 'context', 'kind'));
        $this->assertSame('unclosed_at_flush', $this->valueAt($array, 'events', 0, 'context', 'kind'));
        $this->assertSame([$openId], $this->valueAt($array, 'events', 0, 'context', 'unclosedIds'));
    }

    public function testTotalValidCloseSerializationFailurePopsAndCommitsPlaceholder(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $openId = $logger->open(new ModeTestContext('open'));
        $logger->close(new ModeThrowingContext(), $openId);

        $array = $logger->flush()->toArray();

        $this->assertSame('semantic_logger_invalid_context', $this->valueAt($array, 'open', 0, 'close', 'type'));
        $this->assertSame('close', $this->valueAt($array, 'open', 0, 'close', 'context', 'operation'));
        $this->assertSame('context_serialization_failed', $this->valueAt($array, 'open', 0, 'events', 0, 'context', 'kind'));
        $this->assertArrayNotHasKey('events', $array);
    }

    public function testTotalSessionlessFlushIsIdempotentAndResetsCounter(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);

        $this->assertSame(['$schema' => ModeTestContext::ROOT_SCHEMA, 'mode' => 'total', 'open' => []], $logger->flush()->toArray());
        $this->assertSame(['$schema' => ModeTestContext::ROOT_SCHEMA, 'mode' => 'total', 'open' => []], $logger->flush()->toArray());

        $logger->event(new ModeTestContext('event'));
        $this->assertSame('mode_context_1', $this->valueAt($logger->flush()->toArray(), 'events', 0, 'id'));
    }

    public function testTotalUnclosedFlushIncludesLiveTopologyAndResets(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $outerId = $logger->open(new ModeTestContext('outer'));
        $childId = $logger->open(new ModeTestContext('child'));
        $logger->close(new ModeTestContext('child close'), $childId);

        $array = $logger->flush()->toArray();

        $this->assertSame($outerId, $array['open'][0]['id']);
        $this->assertSame($childId, $this->valueAt($array, 'open', 0, 'open', 0, 'id'));
        $this->assertSame('mode_context_3', $this->valueAt($array, 'open', 0, 'open', 0, 'close', 'id'));
        $this->assertArrayNotHasKey('close', $this->arrayAt($array, 'open', 0));
        $this->assertSame([$outerId], $this->valueAt($array, 'events', 0, 'context', 'unclosedIds'));

        $nextId = $logger->open(new ModeTestContext('next session'));
        $this->assertSame('mode_context_1', $nextId);
    }

    public function testTotalSessionlessSnapshotIsEmptyAndNonDestructive(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);

        $this->assertSame($logger->toArray(), $logger->toArray());
        $logger->event(new ModeTestContext('event'));
        $this->assertSame('mode_context_1', $this->valueAt($logger->flush()->toArray(), 'events', 0, 'id'));
    }

    public function testTotalUnclosedSnapshotsDoNotAccumulateDiagnostics(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $openId = $logger->open(new ModeTestContext('open'));

        $first = $logger->toArray();
        $second = $logger->toArray();

        $this->assertSame($first, $second);
        $this->assertSame($openId, $first['open'][0]['id']);
        $this->assertSame('unclosed_at_flush', $this->valueAt($first, 'events', 0, 'context', 'kind'));

        $flushed = $logger->flush()->toArray();
        $this->assertCount(1, $this->arrayAt($flushed, 'events'));
        $this->assertSame('semantic_logger_error_1', $this->valueAt($flushed, 'events', 0, 'id'));
    }

    public function testStrictFlushRecordsModeInEnvelope(): void
    {
        $logger = new SemanticLogger();
        $logger->event(new ModeTestContext('event'));

        $array = $logger->flush()->toArray();

        $this->assertSame('strict', $array['mode'] ?? null);
    }

    public function testTotalFlushRecordsModeInEnvelope(): void
    {
        $logger = new SemanticLogger(SemanticLoggerMode::Total);
        $logger->event(new ModeTestContext('event'));

        $array = $logger->flush()->toArray();

        $this->assertSame('total', $array['mode'] ?? null);
    }

    /** @param array<array-key, mixed> $array */
    private function valueAt(array $array, int|string ...$path): mixed
    {
        $value = $array;
        foreach ($path as $key) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return array<array-key, mixed>
     */
    private function arrayAt(array $array, int|string ...$path): array
    {
        $value = $this->valueAt($array, ...$path);
        $this->assertIsArray($value);

        return $value;
    }
}
