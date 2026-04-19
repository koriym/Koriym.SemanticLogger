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
use function is_numeric;
use function is_readable;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function substr;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;
use const STDERR;
use const STDOUT;

final class StreeCommand
{
    private const DEFAULT_MAX_LINES = 5;

    /** @param string[] $argv */
    public function __invoke(array $argv): int
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

            /** @var mixed $threshold */
            $threshold = $options['threshold'] ?? 0.0;
            /** @var mixed $lines */
            $lines = $options['lines'] ?? self::DEFAULT_MAX_LINES;
            $config = new RenderConfig(
                (bool) ($options['full'] ?? false),
                is_numeric($threshold) ? (float) $threshold : 0.0,
                is_numeric($lines) ? (int) $lines : self::DEFAULT_MAX_LINES,
                (bool) ($options['values'] ?? false),
            );

            /** @var mixed $fileOption */
            $fileOption = $options['file'];
            if (! is_string($fileOption)) {
                throw new RuntimeException('File option must be a string');
            }

            $logData = $this->loadLogFile($fileOption);

            if (isset($options['json'])) {
                echo json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }

            $renderer = new TreeRenderer();
            $output = $renderer->render($logData, $config);
            echo $output . "\n";

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
            $result = $this->parseArgument($arg, $args, $i);

            if ($result['consumed']) {
                /** @var int $index */
                $index = $result['index'];
                $i = $index;
            }

            if (isset($result['option'])) {
                /** @var array<string, mixed> $option */
                $option = $result['option'];
                $options = $this->mergeOptions($options, $option);
            }
        }

        return $options;
    }

    /**
     * @param string[] $args
     *
     * @return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD)
     */
    private function parseArgument(string $arg, array $args, int $index): array
    {
        // Handle simple flags
        $simpleFlags = ['--help' => 'help', '-h' => 'help', '--full' => 'full', '-f' => 'full', '--json' => 'json', '--values' => 'values', '-V' => 'values'];
        if (isset($simpleFlags[$arg])) {
            return ['option' => [$simpleFlags[$arg] => true], 'consumed' => false, 'index' => $index];
        }

        if ($arg === '--threshold' || $arg === '-t') {
            return $this->parseThresholdOption($args, $index);
        }

        if ($arg === '--lines' || $arg === '-l') {
            return $this->parseLinesOption($args, $index);
        }

        // Handle assignment format
        if (str_starts_with($arg, '--threshold=')) {
            return $this->parseThresholdAssignment($arg);
        }

        if (str_starts_with($arg, '--lines=')) {
            return $this->parseLinesAssignment($arg);
        }

        // Handle log file
        if ($arg[0] !== '-') {
            return ['option' => ['file' => $arg], 'consumed' => false, 'index' => $index];
        }

        // Unknown option
        throw new RuntimeException(sprintf('Unknown option: %s', $arg));
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

            $threshold = (float) $numericValue / 1000.0;
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
            throw new RuntimeException(sprintf('Failed to read log file: %s', $file)); // @codeCoverageIgnore
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return $data;
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Invalid JSON in log file: %s', $e->getMessage()));
        }
    }

    /**
     * @param string[] $args
     *
     * @return array<string, mixed>
     */
    private function parseThresholdOption(array $args, int $index): array
    {
        if (! isset($args[$index + 1])) {
            throw new RuntimeException('--threshold requires a value');
        }

        $value = $args[$index + 1];
        $threshold = $this->parseThreshold($value);

        return ['option' => ['threshold' => $threshold], 'consumed' => true, 'index' => $index + 1];
    }

    /** @return array<string, mixed> */
    private function parseThresholdAssignment(string $arg): array
    {
        $value = substr($arg, 12);
        $threshold = $this->parseThreshold($value);

        return ['option' => ['threshold' => $threshold], 'consumed' => false, 'index' => 0];
    }

    /**
     * @param string[] $args
     *
     * @return array<string, mixed>
     */
    private function parseLinesOption(array $args, int $index): array
    {
        if (! isset($args[$index + 1])) {
            throw new RuntimeException('--lines requires a value');
        }

        $linesValue = $args[$index + 1];
        if (! is_numeric($linesValue)) {
            throw new RuntimeException(sprintf('--lines must be a number, got: %s', $linesValue));
        }

        $lines = (int) $linesValue;
        if ($lines < 0) {
            throw new RuntimeException('--lines must be 0 or greater (0 = no limit)');
        }

        return ['option' => ['lines' => $lines], 'consumed' => true, 'index' => $index + 1];
    }

    /** @return array<string, mixed> */
    private function parseLinesAssignment(string $arg): array
    {
        $value = substr($arg, 8);
        if (! is_numeric($value)) {
            throw new RuntimeException(sprintf('--lines must be a number, got: %s', $value));
        }

        $lines = (int) $value;
        if ($lines < 0) {
            throw new RuntimeException('--lines must be 0 or greater (0 = no limit)');
        }

        return ['option' => ['lines' => $lines], 'consumed' => false, 'index' => 0];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     *
     * @return array<string, mixed>
     *
     * @psalm-suppress MixedAssignment
     */
    private function mergeOptions(array $existing, array $new): array
    {
        foreach ($new as $key => $value) {
            $existing[$key] = $value;
        }

        return $existing;
    }

    /** @codeCoverageIgnore */
    private function showUsage(): void
    {
        fprintf(STDERR, "Usage: stree [OPTIONS] <logfile.json>\n");
    }

    /** @codeCoverageIgnore */
    private function showHelp(): void
    {
        $help = <<<'HELP'
stree - Semantic Tree Visualizer for SemanticLogger

USAGE:
    stree [OPTIONS] <logfile.json>

ARGUMENTS:
    <logfile.json>    Path to SemanticLogger JSON output file

OPTIONS:
    -t, --threshold=T Time threshold filter (e.g., 10ms, 0.5s)
    -l, --lines=N     Maximum lines to show for multi-line data (default: 5, 0 = no limit)
    -f, --full        Show full tree with all context keys as leaves
    -V, --values      Opt-in flag consulted by registered formatters to show values
    --json            Pass through raw JSON (pretty-printed)
    -h, --help        Display this help message

EXAMPLES:
    stree debug.json                          # Compact tree
    stree --full debug.json                   # Full tree with all context keys
    stree --threshold=10ms slow.json          # Show only operations > 10ms
    stree --json debug.json                   # Pretty-print JSON passthrough

HELP;
        fprintf(STDOUT, "%s\n", $help);
    }
}
