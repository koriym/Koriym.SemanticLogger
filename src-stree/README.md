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
vendor/bin/stree --json debug.json
```

| Option | Short | Description |
|--------|-------|-------------|
| `--threshold=T` | `-t` | Time threshold filter such as `10ms` or `0.5s` |
| `--lines=N` | `-l` | Maximum lines for multi-line data (`0` means no limit) |
| `--full` | `-f` | Show the full tree with all context keys as leaves |
| `--values` | `-V` | Let registered formatters show values instead of only keys |
| `--json` | | Pretty-print raw JSON |
| `--help` | `-h` | Display help |

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
```

You can also render a plain `{open, close, events}` array directly, without a `LogJson`:

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
