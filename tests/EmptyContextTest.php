<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

use function dirname;
use function json_decode;
use function json_encode;
use function realpath;
use function substr_count;

use const JSON_THROW_ON_ERROR;

/**
 * A context with no public properties is recorded as an empty context, and the
 * JSON document still satisfies the bundled envelope schema.
 */
final class EmptyContextTest extends TestCase
{
    public function testEmptyContextsEncodeAsObjectsTheEnvelopeAccepts(): void
    {
        $logger = new SemanticLogger();
        $id = $logger->open(new PrivateOnlyContext());
        $logger->event(new PrivateOnlyContext());
        $logger->close(new PrivateOnlyContext(), $id);

        $json = json_encode($logger->flush(), JSON_THROW_ON_ERROR);

        $validator = new Validator();
        $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $validator->validate($document, (object) ['$ref' => 'file://' . realpath(dirname(__DIR__) . '/docs/schemas/semantic-log.json')]);
        $this->assertTrue($validator->isValid(), (string) json_encode($validator->getErrors()));
        $this->assertSame(3, substr_count($json, '"context":{}'), 'open, event and close');
    }

    public function testDiscardedEmptyContextEncodesAsObject(): void
    {
        $logger = new SemanticLogger();
        $logger->close(new PrivateOnlyContext(), 'private_only_99');

        $json = json_encode($logger->flush(), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"discardedContext":{}', $json);
    }

    public function testToArrayKeepsContextsAsPhpArrays(): void
    {
        $logger = new SemanticLogger();
        $logger->event(new PrivateOnlyContext());

        $this->assertSame([], $logger->flush()->toArray()['log'][0]['context']);
    }
}
