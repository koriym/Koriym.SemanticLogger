<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
use function strpos;
use function strrpos;

/**
 * stree renders a scope's children in the order the logger recorded them.
 *
 * The document keeps a scope's own events in `events` and its nested scopes in
 * `open`, so the structural order the parser walks shows every event first. When
 * every child carries `seq`, the recorded order is knowable and stree uses it.
 */
final class EntrySequenceOrderTest extends TestCase
{
    public function testEventRecordedAfterNestedScopeRendersAfterIt(): void
    {
        $tree = (new LogDataParser())->parseLogData($this->commandLog(withSeq: true));

        $this->assertSame(['purge', 'get', 'purge'], $this->childTypes($tree));
    }

    /** Without `seq` there is nothing to order by, so structural order stands. */
    public function testDocumentWithoutSequenceKeepsStructuralOrder(): void
    {
        $tree = (new LogDataParser())->parseLogData($this->commandLog(withSeq: false));

        $this->assertSame(['purge', 'purge', 'get'], $this->childTypes($tree));
    }

    public function testRenderedTreeFollowsRecordOrder(): void
    {
        $output = (new TreeRenderer())->renderTree($this->commandLog(withSeq: true));

        // The GET sits between the two purges, not after both of them.
        $this->assertLessThan(strpos($output, 'get'), strpos($output, 'purge'));
        $this->assertGreaterThan(strpos($output, 'get'), strrpos($output, 'purge'));
    }

    /** Ordering must not depend on which array the event arrived in. */
    public function testTopLevelEventsAttachedByOpenIdAreOrderedToo(): void
    {
        $tree = (new LogDataParser())->parseLogData($this->commandLog(withSeq: true, eventsAtTopLevel: true));

        $this->assertSame(['purge', 'get', 'purge'], $this->childTypes($tree));
    }

    /** An orphan close keeps its `seq`; dropping it would disable ordering for the whole scope it lands in. */
    public function testOrphanCloseKeepsItsRecordPosition(): void
    {
        $log = $this->commandLog(withSeq: true);
        $log['close'] = [
            [
                'id' => 'stray_close_1',
                'type' => 'stray_close',
                'schemaUrl' => './schemas/stray_close.json',
                'context' => [],
                'openId' => 'ghost_1',
                'seq' => 7,
            ],
        ];

        $tree = (new LogDataParser())->parseLogData($log);

        $this->assertSame(['command', 'stray_close'], $this->childTypes($tree));
        $this->assertSame(7, $tree->children[1]->seq);
        $this->assertSame(['purge', 'get', 'purge'], $this->childTypes($tree->children[0]));
    }

    /** @return list<string> */
    private function childTypes(TreeNode $node): array
    {
        return array_values(array_map(static fn (TreeNode $child): string => $child->type, $node->children));
    }

    /**
     * command scope that ran purge → refresh(GET) → purge.
     *
     * @return array<string, mixed>
     */
    private function commandLog(bool $withSeq, bool $eventsAtTopLevel = false): array
    {
        $seq = static fn (int $value): array => $withSeq ? ['seq' => $value] : [];
        $events = [
            ['id' => 'purge_1', 'type' => 'purge', 'schemaUrl' => './schemas/purge.json', 'context' => [], ...$seq(2)],
            ['id' => 'purge_2', 'type' => 'purge', 'schemaUrl' => './schemas/purge.json', 'context' => [], ...$seq(5)],
        ];
        $command = [
            'id' => 'command_1',
            'type' => 'command',
            'schemaUrl' => './schemas/command.json',
            'context' => [],
            ...$seq(1),
            'open' => [
                [
                    'id' => 'get_1',
                    'type' => 'get',
                    'schemaUrl' => './schemas/get.json',
                    'context' => [],
                    ...$seq(3),
                    'close' => [
                        'id' => 'get_close_1',
                        'type' => 'get_close',
                        'schemaUrl' => './schemas/get_close.json',
                        'context' => [],
                        ...$seq(4),
                    ],
                ],
            ],
            'close' => [
                'id' => 'command_close_1',
                'type' => 'command_close',
                'schemaUrl' => './schemas/command_close.json',
                'context' => [],
                ...$seq(6),
            ],
        ];

        if (! $eventsAtTopLevel) {
            $command['events'] = $events;

            return ['open' => [$command]];
        }

        // Same two purges, delivered through the top-level events section.
        $topLevelEvents = [];
        foreach ($events as $event) {
            $topLevelEvents[] = [...$event, 'openId' => 'command_1'];
        }

        return ['open' => [$command], 'events' => $topLevelEvents];
    }
}
