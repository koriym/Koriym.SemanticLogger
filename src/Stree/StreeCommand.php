<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use JsonException;
use RuntimeException;

use function array_slice;
use function count;
use function file_exists;
use function file_get_contents;
use function fprintf;
use function in_array;
use function is_numeric;
use function is_readable;
use function json_decode;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function substr;

use const JSON_THROW_ON_ERROR;
use const STDERR;
use const STDOUT;

final class StreeCommand
{
    private const DEFAULT_DEPTH = 2;
    private const DEFAULT_MAX_LINES = 5;

    /** @param string[] $argv */
    public function run(array $argv): int
    {
        try {
            $options = $this->parseOptions($argv);

            if (isset($options['help'])) {
                $this->showHelp();

                return 0;
            }

            if (! isset($options['file'])) {
                fprintf(STDERR, "Error: Log file required\n");
                $this->showUsage();

                return 1;
            }

            $config = new RenderConfig(
                (int) ($options['depth'] ?? self::DEFAULT_DEPTH),
                (array) ($options['expand'] ?? []),
                (float) ($options['threshold'] ?? 0.0),
                (bool) ($options['full'] ?? false),
                (int) ($options['lines'] ?? self::DEFAULT_MAX_LINES),
            );

            $logData = $this->loadLogFile($options['file']);
            
            $format = $options['format'] ?? 'text';
            if ($format === 'html') {
                $htmlRenderer = new HtmlRenderer();
                $parser = new LogDataParser();
                $tree = $parser->parseLogData($logData);
                $output = $htmlRenderer->render($tree, $config);
            } else {
                $renderer = new TreeRenderer();
                $output = $renderer->render($logData, $config);
            }

            echo $output . ($format === 'text' ? "\n" : '');

            return 0;
        } catch (RuntimeException $e) {
            fprintf(STDERR, "Error: %s\n", $e->getMessage());

            return 1;
        }
    }

    /**
     * @param string[] $argv
     *
     * @return array<string, mixed>
     */
    private function parseOptions(array $argv): array
    {
        $options = [];
        $args = array_slice($argv, 1); // Remove script name

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
            } elseif ($arg === '--full' || $arg === '-f') {
                $options['full'] = true;
            } elseif ($arg === '--format') {
                if (! isset($args[$i + 1])) {
                    throw new RuntimeException('--format requires a value');
                }
                
                $formatValue = $args[++$i];
                if (! in_array($formatValue, ['text', 'html'], true)) {
                    throw new RuntimeException('--format must be "text" or "html"');
                }
                $options['format'] = $formatValue;
            } elseif (str_starts_with($arg, '--format=')) {
                $value = substr($arg, 9);
                if (! in_array($value, ['text', 'html'], true)) {
                    throw new RuntimeException('--format must be "text" or "html"');
                }
                $options['format'] = $value;
            } elseif ($arg === '--depth' || $arg === '-d') {
                if (! isset($args[$i + 1])) {
                    throw new RuntimeException('--depth requires a value');
                }

                $depthValue = $args[++$i];
                if (! is_numeric($depthValue)) {
                    throw new RuntimeException(sprintf('--depth must be a number, got: %s', $depthValue));
                }
                $depth = (int) $depthValue;
                if ($depth < 0) {
                    throw new RuntimeException('--depth must be 0 or greater');
                }
                $options['depth'] = $depth;
            } elseif (str_starts_with($arg, '--depth=')) {
                $value = substr($arg, 8);
                if (! is_numeric($value)) {
                    throw new RuntimeException(sprintf('--depth must be a number, got: %s', $value));
                }
                $depth = (int) $value;
                if ($depth < 0) {
                    throw new RuntimeException('--depth must be 0 or greater');
                }
                $options['depth'] = $depth;
            } elseif ($arg === '--expand' || $arg === '-e') {
                if (! isset($args[$i + 1])) {
                    throw new RuntimeException('--expand requires a value');
                }

                $expandType = $args[++$i];
                // Context types are user-defined, so we accept any non-empty string
                if (empty($expandType)) {
                    throw new RuntimeException('--expand context type cannot be empty');
                }
                $options['expand'][] = $expandType;
            } elseif (str_starts_with($arg, '--expand=')) {
                $value = substr($arg, 9);
                if (empty($value)) {
                    throw new RuntimeException('--expand context type cannot be empty');
                }
                $options['expand'][] = $value;
            } elseif ($arg === '--threshold' || $arg === '-t') {
                if (! isset($args[$i + 1])) {
                    throw new RuntimeException('--threshold requires a value');
                }

                $value = $args[++$i];
                $options['threshold'] = $this->parseThreshold($value);
            } elseif (str_starts_with($arg, '--threshold=')) {
                $value = substr($arg, 12);
                $options['threshold'] = $this->parseThreshold($value);
            } elseif ($arg === '--lines' || $arg === '-l') {
                if (! isset($args[$i + 1])) {
                    throw new RuntimeException('--lines requires a value');
                }

                $linesValue = $args[++$i];
                if (! is_numeric($linesValue)) {
                    throw new RuntimeException(sprintf('--lines must be a number, got: %s', $linesValue));
                }
                $lines = (int) $linesValue;
                if ($lines < 0) {
                    throw new RuntimeException('--lines must be 0 or greater (0 = no limit)');
                }
                $options['lines'] = $lines;
            } elseif (str_starts_with($arg, '--lines=')) {
                $value = substr($arg, 8);
                if (! is_numeric($value)) {
                    throw new RuntimeException(sprintf('--lines must be a number, got: %s', $value));
                }
                $lines = (int) $value;
                if ($lines < 0) {
                    throw new RuntimeException('--lines must be 0 or greater (0 = no limit)');
                }
                $options['lines'] = $lines;
            } elseif ($arg[0] !== '-') {
                // This is the log file
                $options['file'] = $arg;
            } else {
                throw new RuntimeException(sprintf('Unknown option: %s', $arg));
            }
        }

        return $options;
    }

    private function parseThreshold(string $value): float
    {
        // Parse threshold value like "10ms", "0.5s"
        $threshold = 0.0;
        
        if (str_ends_with($value, 'ms')) {
            $numericValue = substr($value, 0, -2);
            if (! is_numeric($numericValue)) {
                throw new RuntimeException(sprintf('Invalid threshold format: %s (expected: 10ms, 0.5s)', $value));
            }
            $threshold = (float) $numericValue / 1000;
        } elseif (str_ends_with($value, 's')) {
            $numericValue = substr($value, 0, -1);
            if (! is_numeric($numericValue)) {
                throw new RuntimeException(sprintf('Invalid threshold format: %s (expected: 10ms, 0.5s)', $value));
            }
            $threshold = (float) $numericValue;
        } else {
            if (! is_numeric($value)) {
                throw new RuntimeException(sprintf('Invalid threshold format: %s (expected: 10ms, 0.5s)', $value));
            }
            $threshold = (float) $value;
        }

        if ($threshold < 0) {
            throw new RuntimeException('--threshold must be 0 or greater');
        }

        return $threshold;
    }

    /** @return array<string, mixed> */
    private function loadLogFile(string $file): array
    {
        if (! file_exists($file)) {
            throw new RuntimeException(sprintf('Log file not found: %s', $file));
        }

        if (! is_readable($file)) {
            throw new RuntimeException(sprintf('Log file not readable: %s', $file));
        }

        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException(sprintf('Failed to read log file: %s', $file));
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return $data;
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid JSON in log file: %s', $e->getMessage()));
        }
    }

    private function showUsage(): void
    {
        fprintf(STDERR, "Usage: stree [OPTIONS] <logfile.json>\n");
    }

    private function showHelp(): void
    {
        $help = <<<'HELP'
stree - Semantic Tree Visualizer for SemanticLogger

USAGE:
    stree [OPTIONS] <logfile.json>

ARGUMENTS:
    <logfile.json>    Path to SemanticLogger JSON output file

OPTIONS:
    -d, --depth=N     Maximum tree depth to display (default: 2)
    -e, --expand=CTX  Expand specific context types beyond depth limit
    -t, --threshold=T Time threshold filter (e.g., 10ms, 0.5s)
    -l, --lines=N     Maximum lines to show for multi-line data (default: 5, 0 = no limit)
    --format=FORMAT   Output format: text (default) or html
    -f, --full        Show complete tree without depth limits
    -h, --help        Display this help message

EXAMPLES:
    stree debug.json                          # Default 2-level tree
    stree --depth=5 detailed.json             # Show 5 levels deep
    stree --expand=DatabaseQuery log.json     # Expand DatabaseQuery contexts
    stree --threshold=10ms slow.json          # Show only operations > 10ms
    stree --lines=10 detailed.json            # Show up to 10 lines of headers/params
    stree --format=html --full trace.json     # Interactive HTML output
    stree --format=html trace.json > trace.html  # Save HTML to file

HELP;
        fprintf(STDOUT, "%s\n", $help);
    }
}
