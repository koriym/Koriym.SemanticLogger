<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;
use stdClass;

use function array_map;
use function is_array;

/**
 * @psalm-import-type LogRecordList from Types
 * @psalm-import-type LogSessionArray from Types
 * @psalm-import-type SchemaLinks from Types
 */
final class LogJson implements JsonSerializable
{
    /**
     * @param LogRecordList $records Opens, events and closes in record order.
     * @param SchemaLinks   $links
     */
    public function __construct(
        public readonly string $schemaUrl,
        public readonly array $records,
        public readonly array $links = [],
    ) {
    }

    /**
     * The JSON document. Same as toArray(), except that an empty context (and an
     * empty diagnostic `discardedContext`) is a JSON object: as a PHP array it
     * would encode as `[]`, which the envelope schema rejects.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        $document = $this->toArray();
        $document['log'] = array_map(self::objectContexts(...), $document['log']);

        return $document;
    }

    /**
     * Render this log with the given renderer (double dispatch).
     *
     * The log does not know any concrete output format. It hands itself back to
     * the renderer, which decides how to present it (tree, markdown, ...), so
     * output formats can be swapped without touching the log.
     */
    public function render(LogRendererInterface $renderer): string
    {
        return $renderer->render($this);
    }

    /** @return LogSessionArray */
    public function toArray(): array
    {
        $log = (new LogTreeBuilder())->build($this->records);
        $result = [
            '$schema' => $this->schemaUrl,
            'log' => $log,
        ];

        if ($this->links !== []) {
            $result['links'] = $this->links;
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $record
     *
     * @return array<array-key, mixed>
     */
    private static function objectContexts(array $record): array
    {
        if (isset($record['context']) && is_array($record['context'])) {
            $context = $record['context'];
            if (($record['type'] ?? null) === CoreSchema::DIAGNOSTIC_TYPE && isset($context['discardedContext']) && is_array($context['discardedContext'])) {
                $context['discardedContext'] = self::asObject($context['discardedContext']);
            }

            $record['context'] = self::asObject($context);
        }

        if (isset($record['close']) && is_array($record['close'])) {
            $record['close'] = self::objectContexts($record['close']);
        }

        if (isset($record['log']) && is_array($record['log'])) {
            $record['log'] = array_map(
                static fn (mixed $child): mixed => is_array($child) ? self::objectContexts($child) : $child,
                $record['log'],
            );
        }

        return $record;
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>|stdClass
     */
    private static function asObject(array $context): array|stdClass
    {
        return $context === [] ? new stdClass() : $context;
    }
}
