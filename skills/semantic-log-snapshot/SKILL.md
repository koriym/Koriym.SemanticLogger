---
name: semantic-log-snapshot
description: Pin the behavior of code instrumented with koriym/semantic-logger as a snapshot test over its rendered log tree. Use when adding a regression test that compares a scenario's semantic log to a stored file, making such a test stable across runs (`stree --snapshot`, `RenderConfig(snapshot: true)`, `"x-volatile": true` in context schemas), updating stored snapshots, or deciding why a snapshot differs between two identical runs.
---

# Semantic Log Snapshot

## Overview

A flushed semantic log is a record of what a scenario did: every operation, its context and its result, nested as they ran. Rendered with snapshot mode, the same scenario renders the same bytes on every run, so the rendered tree can be stored and compared. The stored file is the expected behavior; a diff is a change in behavior.

## Workflow

1. Write a deterministic scenario.
- A script or a test that runs the operations and calls `$logger->flush()`.
- Fixed inputs, no network, a fresh store per run. Anything the scenario reads from the clock or a random source makes the log differ; inject a fixed clock or ID generator where the code allows it.

2. Render in snapshot mode.
- In process: `$log->render(new TreeRenderer(new RenderConfig(true, 0.0, 0, snapshot: true, schemaDir: $schemaDir)))`. `true` is the full tree, `0.0` no time threshold, `0` no line limit.
- From a file: `vendor/bin/stree --snapshot --full --lines=0 --schema-dir=<schemas> log.json`.
- Snapshot mode drops execution times and close profiles. It cannot be combined with a time threshold or `--json`.

3. Mark per-run context values in the schema, not in the test.
- In the context's JSON Schema, add `"x-volatile": true` to a property whose value changes on every run (a timestamp, a request ID, a duration, a path with a generated name). With `schemaDir`, it renders as `[volatile]`.
- The schema is found by the file name of the record's `schemaUrl`. Nested `properties` are followed; `$ref`, `allOf` and `items` are not.
- A formatter that adds its own per-run values reads `$config->snapshot` and leaves them out.

4. Normalize the rendered text only for what neither the schema nor the scenario can fix.
- For example, a third-party message that differs between installed backends, or CRLF line endings on Windows.
- Give every normalization a comment saying which value it masks and why that value cannot be fixed at its source.

5. Store and compare.
- Keep one snapshot file per scenario next to it (for example `tests/snapshots/{name}.log`).
- The test is one assertion: `assertSame(file_get_contents($snapshot), $rendered)`.

6. Update deliberately.
- Provide one command that reruns every scenario and overwrites its snapshot (a `composer` script).
- Read the diff before committing it: it is the change in behavior. Never update a snapshot only to make a failing test pass.

## When two identical runs differ

Do not mark the value volatile first. Find out why it changed.
- It is per-run by nature (a clock, an ID, a duration): mark it in the schema, or fix it in the scenario.
- It should have been stable: it is a bug, and the snapshot found it. A cache validator that changed for unchanged content because a child's `Last-Modified` leaked into it was found this way.

Masking a value that should be stable hides the next regression in that value.

## Example

```php
use Koriym\SemanticLogger\Stree\RenderConfig;
use Koriym\SemanticLogger\Stree\TreeRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScenarioSnapshotTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function scenarios(): array
    {
        return ['checkout' => ['checkout']];
    }

    #[DataProvider('scenarios')]
    public function testLogMatchesSnapshot(string $name): void
    {
        $log = runScenario($name); // runs the operations and returns $logger->flush()
        $rendered = $log->render(new TreeRenderer(new RenderConfig(true, 0.0, 0, snapshot: true, schemaDir: __DIR__ . '/../docs/schemas')));

        $this->assertSame((string) file_get_contents(__DIR__ . "/snapshots/{$name}.log"), $rendered);
    }
}
```

The update command writes the same `$rendered` to `snapshots/{$name}.log` for every scenario.
