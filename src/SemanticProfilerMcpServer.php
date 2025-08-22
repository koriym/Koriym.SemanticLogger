<?php

/**
 * Standalone Semantic Profiler MCP Server
 *
 * AI-powered performance analysis through structured semantic profiling.
 * Fulfilling the vision of Semantic Web - machines understanding meaning.
 *
 * @psalm-import-type McpJsonRpcRequest from Types
 * @psalm-import-type McpJsonRpcResponse from Types
 * @psalm-import-type McpInitializeResult from Types
 * @psalm-import-type McpToolsListResult from Types
 * @psalm-import-type McpToolCallResult from Types
 * @psalm-import-type McpToolCallParams from Types
 * @psalm-import-type McpLogData from Types
 */

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Exception;
use InvalidArgumentException;
use Throwable;

use function array_filter;
use function array_map;
use function array_merge;
use function basename;
use function date;
use function escapeshellarg;
use function extension_loaded;
use function fgets;
use function file_exists;
use function file_get_contents;
use function filemtime;
use function filesize;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function number_format;
use function rtrim;
use function shell_exec;
use function sprintf;
use function strpos;
use function time;
use function trim;
use function usort;

use const JSON_PRETTY_PRINT;
use const STDIN;

final class SemanticProfilerMcpServer
{
    private const SERVER_NAME = 'semantic-profiler';
    private const SERVER_VERSION = '1.0.0';

    private string $logFile;

    public function __construct(private string $logDirectory)
    {
        if (! is_dir($logDirectory)) {
            throw new InvalidArgumentException("Directory not found: $logDirectory");
        }

        // Find the latest semantic log file
        $pattern = rtrim($logDirectory, '/') . '/semantic-log-*.json';
        $files = glob($pattern);

        if ($files === false || empty($files)) {
            throw new InvalidArgumentException("No semantic log files found in directory: $logDirectory");
        }

        // Sort by modification time, get the latest
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $this->logFile = $files[0];
    }

    public function __invoke(): void
    {
        $this->run();
    }

    public function run(): void
    {
        while (true) {
            $input = fgets(STDIN);
            if ($input === false) {
                break;
            }

            $decoded = json_decode(trim($input), true);
            if (! is_array($decoded)) {
                continue;
            }

            /** @var array<string, mixed> $request */
            $request = $decoded;

            try {
                $response = $this->handleRequest($request);
                echo json_encode($response) . "\n";
            } catch (Throwable $e) {
                $error = [
                    'jsonrpc' => '2.0',
                    'id' => $request['id'] ?? null,
                    'error' => [
                        'code' => -32603,
                        'message' => 'Internal error',
                        'data' => $e->getMessage(),
                    ],
                ];
                echo json_encode($error) . "\n";
            }
        }
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function handleRequest(array $request): array
    {
        $method = (string) ($request['method'] ?? '');
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        switch ($method) {
            case 'initialize':
                return $this->initialize($id);

            case 'tools/list':
                return $this->listTools($id);

            case 'tools/call':
                if (! is_array($params)) {
                    throw new Exception('Invalid params for tools/call');
                }

                /** @var array<string, mixed> $params */
                return $this->callTool($params, $id);

            default:
                throw new Exception("Unknown method: $method");
        }
    }

    /** @return array<string, mixed> */
    private function initialize(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [
                    'tools' => [],
                ],
                'serverInfo' => [
                    'name' => self::SERVER_NAME,
                    'version' => self::SERVER_VERSION,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function listTools(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => [
                    [
                        'name' => 'getSemanticProfile',
                        'description' => 'Retrieve latest semantic performance profile - structured data designed for AI understanding and insight generation',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [],
                            'required' => [],
                        ],
                    ],
                    [
                        'name' => 'listSemanticProfiles',
                        'description' => 'List all available semantic profile log files in the directory',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [],
                            'required' => [],
                        ],
                    ],
                    [
                        'name' => 'semanticAnalyze',
                        'description' => 'Execute PHP script with semantic profiling enabled, then automatically generate AI-powered performance insights and recommendations',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'script' => [
                                    'type' => 'string',
                                    'description' => 'Path to PHP script to execute',
                                ],
                                'xdebug_mode' => [
                                    'type' => 'string',
                                    'enum' => ['trace', 'profile', 'debug', 'develop', 'gcstats', 'off'],
                                    'description' => 'Xdebug mode',
                                    'default' => 'trace',
                                ],
                            ],
                            'required' => ['script'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(array $params, mixed $id): array
    {
        $toolName = (string) ($params['name'] ?? '');
        $arguments = $params['arguments'] ?? [];

        switch ($toolName) {
            case 'getSemanticProfile':
                $result = $this->getSemanticProfile();
                break;

            case 'listSemanticProfiles':
                $result = $this->listSemanticProfiles();
                break;

            case 'semanticAnalyze':
                if (! is_array($arguments)) {
                    throw new Exception('Invalid arguments for semanticAnalyze');
                }

                $script = (string) ($arguments['script'] ?? '');
                $xdebugMode = (string) ($arguments['xdebug_mode'] ?? 'trace');
                $result = $this->semanticAnalyze($script, $xdebugMode);
                break;

            default:
                throw new Exception("Unknown tool: $toolName");
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $result,
                    ],
                ],
            ],
        ];
    }

    private function getSemanticProfile(): string
    {
        $logData = $this->getLog($this->logFile);
        $analysisPrompt = $this->getAnalysisPrompt();

        $jsonData = json_encode($logData, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            throw new Exception('Failed to encode log data as JSON');
        }

        return $analysisPrompt . "\n\n```json\n" . $jsonData . "\n```";
    }

    private function listSemanticProfiles(): string
    {
        $pattern = rtrim($this->logDirectory, '/') . '/semantic-log-*.json';
        $files = glob($pattern);

        if ($files === false || empty($files)) {
            return 'No semantic profile files found in directory: ' . $this->logDirectory;
        }

        // Sort by modification time (newest first)
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $fileList = [];
        foreach ($files as $file) {
            $basename = basename($file);
            $size = filesize($file);
            $mtime = filemtime($file);
            if ($size === false || $mtime === false) {
                continue;
            }

            $fileList[] = sprintf(
                '• %s (%s bytes, %s)',
                $basename,
                number_format($size),
                date('Y-m-d H:i:s', $mtime),
            );
        }

        return "Available semantic profile files:\n\n" . implode("\n", $fileList);
    }

    private function semanticAnalyze(string $script, string $xdebugMode): string
    {
        // Validate and sanitize xdebug mode to prevent command injection
        $allowedModes = ['trace', 'profile', 'debug', 'develop', 'gcstats', 'off'];
        if (! in_array($xdebugMode, $allowedModes, true)) {
            $xdebugMode = 'trace';
        }

        if (! $script) {
            throw new Exception('script parameter is required');
        }

        if (! file_exists($script)) {
            throw new Exception("Script not found: $script");
        }

        // Record time before execution to find newly created log files
        $beforeExecution = time();

        // Execute the PHP script with profiling settings via -d options
        // All variables are validated and properly escaped to prevent command injection
        $escapedXdebugMode = escapeshellarg($xdebugMode);
        $escapedScript = escapeshellarg($script);
        $escapedXdebugConfig = escapeshellarg('compression_level=0');

        // Check if profiling extensions are available
        $hasXdebug = extension_loaded('xdebug');
        $hasXHProf = extension_loaded('xhprof');

        $phpOptions = [
            'max_execution_time=30',
            'memory_limit=256M',
            'error_reporting=E_ALL',
            'display_errors=1',
        ];

        // Add extension loading if not already loaded
        if (! $hasXdebug) {
            $phpOptions[] = 'zend_extension=xdebug.so';
        }

        if (! $hasXHProf) {
            $phpOptions[] = 'extension=xhprof.so';
        }

        // Add Xdebug settings only if Xdebug is available or being loaded
        $phpOptions = array_merge($phpOptions, [
            "xdebug.output_dir={$this->logDirectory}",
            'xdebug.start_with_request=no',
            'xdebug.trace_format=1',
            'xdebug.trace_options=10',
            'xdebug.use_compression=0',
            'xdebug.collect_params=1',
            'xdebug.collect_return=1',
            'xdebug.collect_assignments=1',
        ]);
        $phpOptionsString = implode(' ', array_map(static fn ($opt) => "-d $opt", $phpOptions));
        $command = "XDEBUG_MODE=$escapedXdebugMode XDEBUG_CONFIG=$escapedXdebugConfig php $phpOptionsString $escapedScript 2>&1";

        $output = shell_exec($command);

        // Check if profiling extensions failed to load and warn if needed
        if ($output !== null && $output !== false && strpos($output, 'Unable to load dynamic library') !== false) {
            $warningMsg = 'Warning: Some profiling extensions may not be available. ';
            if (! $hasXdebug && ! $hasXHProf) {
                $warningMsg .= 'Neither Xdebug nor XHProf extensions are loaded. ';
            }

            if (! $hasXdebug && $hasXHProf) {
                $warningMsg .= 'Xdebug extension is not loaded. ';
            }

            if ($hasXdebug && ! $hasXHProf) {
                $warningMsg .= 'XHProf extension is not loaded. ';
            }

            $warningMsg .= "Semantic logging will continue with basic functionality.\n";
            $output = $warningMsg . $output;
        }

        // Find semantic log files created during script execution
        $pattern = rtrim($this->logDirectory, '/') . '/semantic-log-*.json';
        $files = glob($pattern);
        if ($files === false) {
            $files = [];
        }

        $newLogFiles = array_filter($files, static fn (string $file): bool => filemtime($file) >= $beforeExecution);

        if (empty($newLogFiles)) {
            return "Script executed but no new semantic log generated.\nOutput:\n$output";
        }

        // Get the newest log file from this execution
        usort($newLogFiles, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $executionLog = $newLogFiles[0];

        // Load and return the log data with analysis prompt
        $logData = $this->getLog($executionLog);
        $analysisPrompt = $this->getAnalysisPrompt();

        $jsonData = json_encode($logData, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            throw new Exception('Failed to encode log data as JSON');
        }

        return "Script executed successfully.\nLog file: $executionLog\n\n" . $analysisPrompt . "\n\n```json\n" . $jsonData . "\n```";
    }

    /** @return array<string, mixed> */
    private function getLog(string $file): array
    {
        $content = file_get_contents($file);

        if ($content === false) {
            return ['error' => 'Failed to read file'];
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            /** @var array<string, mixed> $result */
            $result = $decoded;

            return $result;
        }

        return ['error' => 'Invalid log format'];
    }

    /**
     * Generate analysis prompt for AI-native semantic web processing
     */
    private function getAnalysisPrompt(): string
    {
        return <<<'PROMPT'
This is a semantic profiling log. Analyze YOUR APPLICATION CODE performance, not framework overhead.

The log contains schemaUrl fields. Refer to these schemas to understand the semantic meaning of the data structures.

Focus on business logic within your application code. Ignore framework overhead and profiling overhead.

If no performance issues are found, provide:
- What the code is doing (business purpose)
- How it's implemented (technical approach)
- Implementation assessment (is this approach appropriate for the task?)
- Any architectural observations or suggestions for production readiness

Use the semantic profiling data to provide deep insights about code quality and appropriateness, not just performance metrics.
PROMPT;
    }
}
