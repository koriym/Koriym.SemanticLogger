# Stree — Semantic Tree Visualizer

Render [semantic logs](https://github.com/koriym/Koriym.SemanticLogger) as a readable tree, for humans (visual parent/child structure) and for AI consumers (far fewer tokens than the raw JSON).

> This package lives as a monorepo subpackage under `src-stree/` of
> [koriym/semantic-logger](https://github.com/koriym/Koriym.SemanticLogger).
> Its source, tests, and `bin/stree` are self-contained here and can be
> `git subtree split` into a standalone `koriym/stree` package.

## CLI

```bash
vendor/bin/stree debug.json
vendor/bin/stree --full debug.json
vendor/bin/stree --threshold=10ms slow.json
vendor/bin/stree --snapshot --full debug.json
vendor/bin/stree --snapshot --schema-dir=schemas debug.json
vendor/bin/stree --json debug.json
```

| Option | Short | Description |
|--------|-------|-------------|
| `--threshold=T` | `-t` | Time threshold filter such as `10ms` or `0.5s` |
| `--lines=N` | `-l` | Maximum lines for multi-line data (`0` means no limit) |
| `--full` | `-f` | Show the full tree with all context keys as leaves |
| `--values` | `-V` | Let registered formatters show values instead of only keys |
| `--snapshot` | | Omit per-run values (execution times, close profiles) for snapshot tests; cannot be combined with `--threshold` or `--json` |
| `--schema-dir=DIR` | | With `--snapshot`, render the values the context schemas in `DIR` mark `"x-volatile": true` as `[volatile]` |
| `--json` | | Pretty-print raw JSON |
| `--help` | `-h` | Display help |

## Output

A scope prints its open line first, then its events and nested scopes as children,
then its close entry last: the close TYPE followed by the signals that changed
since open. The close type is the outcome of the scope, so it leads the line.

```text
get uri=app://self/user?id=1
├── save_value uri=app://self/user?id=1 tags=[_user_id=1] ttl=31536000 saved=true [event]
└── cache_miss layer=resource
get uri=app://self/user?id=1
└── cache_hit layer=resource
```

A scope that never closed carries `: unclosed` instead of a close line. In `--full`
mode the close entry keeps its own branch, labelled with the type, and its context
and profile hang below it:

```text
get uri=app://self/user?id=1
├── uri: app://self/user?id=1
└── close cache_hit
    └── layer: resource
```

## Formatters

A package renders its own node types by declaring formatters in its `composer.json`.
`vendor/bin/stree` reads the declarations from Composer's `installed.json`, which lists the installed packages but not the root project:

```json
"extra": {
    "stree": {
        "formatters": {
            "resource_request": "Vendor\\Package\\Stree\\ResourceRequestFormatter"
        }
    }
}
```

The class implements `NodeFormatterInterface`, takes no constructor arguments, and returns the node's
line; stree appends the status (`: unclosed`) and the `[event]` marker, and renders the children and the
close line as usual. An entry whose class is missing, does not
implement the interface, or cannot be constructed without arguments is skipped. In process,
`FormatterRegistry::fromInstalledPackages('vendor/composer/installed.json')` builds the same registry
for `RenderConfig`.

## Snapshot output

`--snapshot` (`RenderConfig::$snapshot`) drops the values that change on every run — the `[12.0ms]` execution time and, in `--full`, the close `profile` (wall time and trace file paths) — so the same operation renders the same bytes. A time threshold depends on those timings, so combining it with snapshot is an error, as is `--json`, which passes the log through unrendered.

A context schema marks its own per-run properties with `"x-volatile": true`. With `--schema-dir` (`RenderConfig::$schemaDir`), those values render as `[volatile]` and no longer differ between runs. A schema is found by the file name of the record's `schemaUrl`, as the validator does; nested `properties` are followed, `$ref`, `allOf` and `items` are not:

```json
"requestId": { "type": "string", "x-volatile": true },
"headers": {
    "type": "object",
    "properties": { "X-Request-ID": { "type": "string", "x-volatile": true } }
}
```

A value a schema cannot mark as per-run, such as a file name that embeds a generated ID, is rendered as is: fix it in the test (inject a fixed clock or ID generator) or replace it in the rendered text before comparing. A formatter should read `$config->snapshot` and leave out its own per-run values.

## In process

`stree` is a thin CLI over the same renderer you can call directly. `TreeRenderer`
implements the core `Koriym\SemanticLogger\LogRendererInterface`, so a `LogJson`
can render itself with it via double dispatch — the log never needs to know the
output format:

```php
use Koriym\SemanticLogger\Stree\TreeRenderer;
use Koriym\SemanticLogger\Stree\RenderConfig;

$log = $logger->flush();

echo $log->render(new TreeRenderer());                              // compact tree
echo $log->render(new TreeRenderer(new RenderConfig(true, 0.0, 5))); // full tree
echo $log->render(new TreeRenderer(new RenderConfig(true, 0.0, 5, snapshot: true))); // full tree for a snapshot test
```

You can also render a plain `{entries, close}` array directly, without a `LogJson`:

```php
echo (new TreeRenderer())->renderTree($logData);
```

## Components

- `TreeRenderer` — draws the tree; implements `LogRendererInterface`
- `LogDataParser` — turns the log array into a `TreeNode` graph
- `SignalExtractor` — domain-agnostic 1-line signal compression (`signal=value (+N more)`)
- `RenderConfig` — rendering options (full mode, time threshold, max lines, …)
- `FormatterRegistry` / `NodeFormatterInterface` — per-type custom value formatting
- `TreeNode` — immutable tree node value object

## License

MIT
