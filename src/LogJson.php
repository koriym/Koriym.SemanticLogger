<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use JsonSerializable;
use Override;

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

    /** @return LogSessionArray */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
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
}
