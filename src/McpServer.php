<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Exception;
use InvalidArgumentException;
use Koriym\SemanticLogger\Exception\InvalidParamsException;
use Throwable;

use function array_filter;
use function array_map;
use function array_merge;
use function basename;
use function date;
use function error_log;
use function escapeshellarg;
use function extension_loaded;
use function feof;
use function fflush;
use function fgets;
use function file_exists;
use function file_get_contents;
use function filemtime;
use function filesize;
use function getenv;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error;
use function number_format;
use function rtrim;
use function shell_exec;
use function sprintf;
use function strpos;
use function time;
use function trim;
use function usort;

use const JSON_ERROR_NONE;
use const JSON_PRETTY_PRINT;
use const STDIN;
use const STDOUT;

/**
 * Semantic Profiler MCP Server
 *
 * AI-powered performance analysis through structured semantic profiling.
 * Fulfilling the vision of Semantic Web - machines understanding meaning.
 */
final class McpServer
{
    private const SERVER_NAME = 'semantic-profiler';
    private const SERVER_VERSION = '1.0.0';

    private string $logFile;
    private bool $debugMode = false;

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

        $this->debugMode = (bool) (getenv('MCP_DEBUG') ?: false);
    }

    public function __invoke(): void
    {
        try {
            $input = '';

            if (feof(STDIN)) {
                return;
            }

            while (($line = fgets(STDIN)) !== false) {
                $input .= $line;

                if ($this->isCompleteJsonRpc($input)) {
                    $request = json_decode(trim($input), true);

                    if ($request === null || ! is_array($request)) {
                        $this->sendErrorResponse(null, -32700, 'Parse error');
                        $input = '';
                        continue;
                    }

                    /** @var array<string, mixed> $request */
                    $this->debugLog('Received request', $request);

                    try {
                        $response = $this->handleRequest($request);

                        if ($response !== null) {
                            $this->sendResponse($response);
                        }
                    } catch (Throwable $e) {
                        $this->handleException($e, $request['id'] ?? null);
                    }

                    $input = '';
                }
            }
        } catch (Throwable $e) {
            error_log('MCP Server Fatal Error: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        }
    }

    private function isCompleteJsonRpc(string $input): bool
    {
        $trimmed = trim($input);
        if (empty($trimmed)) {
            return false;
        }

        json_decode($trimmed);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>|null
     */
    private function handleRequest(array $request): array|null
    {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        if (! is_string($method)) {
            return $this->createErrorResponse($id, -32600, 'Invalid Request: method must be string');
        }

        if (! is_array($params)) {
            return $this->createErrorResponse($id, -32600, 'Invalid Request: params must be array');
        }

        /** @var array<string, mixed> $params */

        try {
            switch ($method) {
                case 'initialize':
                    return $this->handleInitialize($id, $params);

                case 'tools/list':
                    return $this->handleToolsList($id);

                case 'tools/call':
                    return $this->handleToolCall($id, $params);

                case 'notifications/initialized':
                    return null;

                default:
                    return $this->createErrorResponse($id, -32601, "Method not found: {$method}");
            }
        } catch (Throwable $e) {
            return $this->createErrorResponse($id, -32000, 'Server error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function handleInitialize(mixed $id, array $params): array
    {
        $clientVersion = $params['protocolVersion'] ?? '2024-11-05';
        $supportedVersions = ['2024-11-05'];

        if (! in_array($clientVersion, $supportedVersions)) {
            $clientVersion = '2024-11-05';
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => $clientVersion,
                'capabilities' => [
                    'tools' => ['listChanged' => true],
                ],
                'serverInfo' => [
                    'name' => self::SERVER_NAME,
                    'version' => self::SERVER_VERSION,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function handleToolsList(mixed $id): array
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
    private function handleToolCall(mixed $id, array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (! is_string($toolName)) {
            throw new InvalidParamsException('Tool name must be a string');
        }

        if (! is_array($arguments)) {
            throw new InvalidParamsException('Arguments must be an array');
        }

        /** @var array<string, mixed> $arguments */

        try {
            $result = $this->executeTool($toolName, $arguments);

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
        } catch (Throwable $e) {
            return $this->createErrorResponse($id, -32000, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $arguments */
    private function executeTool(string $toolName, array $arguments): string
    {
        switch ($toolName) {
            case 'getSemanticProfile':
                return $this->getSemanticProfile();

            case 'listSemanticProfiles':
                return $this->listSemanticProfiles();

            case 'semanticAnalyze':
                $scriptArg = $arguments['script'] ?? '';
                $xdebugModeArg = $arguments['xdebug_mode'] ?? 'trace';

                if (! is_string($scriptArg)) {
                    throw new Exception('Script argument must be a string');
                }

                if (! is_string($xdebugModeArg)) {
                    throw new Exception('Xdebug mode argument must be a string');
                }

                $script = $scriptArg;
                $xdebugMode = $xdebugModeArg;

                return $this->semanticAnalyze($script, $xdebugMode);

            default:
                throw new Exception("Unknown tool: $toolName");
        }
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
        $this->validateAnalyzeParameters($script, $xdebugMode);

        $beforeExecution = time();
        $output = $this->executeScriptWithProfiling($script, $xdebugMode);

        $newLogFiles = $this->findNewLogFiles($beforeExecution);
        if (empty($newLogFiles)) {
            return "Script executed but no new semantic log generated.\nOutput:\n$output";
        }

        return $this->formatAnalysisResult($newLogFiles);
    }

    private function validateAnalyzeParameters(string $script, string &$xdebugMode): void
    {
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
    }

    private function executeScriptWithProfiling(string $script, string $xdebugMode): string
    {
        $escapedXdebugMode = escapeshellarg($xdebugMode);
        $escapedScript = escapeshellarg($script);
        $escapedXdebugConfig = escapeshellarg('compression_level=0');

        $phpOptions = $this->buildPhpOptions();
        $phpOptionsString = implode(' ', array_map(static fn ($opt) => "-d $opt", $phpOptions));
        $command = "XDEBUG_MODE=$escapedXdebugMode XDEBUG_CONFIG=$escapedXdebugConfig php $phpOptionsString $escapedScript 2>&1";

        $output = shell_exec($command);

        return $this->processExecutionOutput($output);
    }

    /** @return string[] */
    private function buildPhpOptions(): array
    {
        $hasXdebug = extension_loaded('xdebug');
        $hasXHProf = extension_loaded('xhprof');

        $phpOptions = [
            'max_execution_time=30',
            'memory_limit=256M',
            'error_reporting=E_ALL',
            'display_errors=1',
        ];

        if (! $hasXdebug) {
            $phpOptions[] = 'zend_extension=xdebug.so';
        }

        if (! $hasXHProf) {
            $phpOptions[] = 'extension=xhprof.so';
        }

        return array_merge($phpOptions, [
            "xdebug.output_dir={$this->logDirectory}",
            'xdebug.start_with_request=no',
            'xdebug.trace_format=1',
            'xdebug.trace_options=10',
            'xdebug.use_compression=0',
            'xdebug.collect_params=1',
            'xdebug.collect_return=1',
            'xdebug.collect_assignments=1',
        ]);
    }

    private function processExecutionOutput(string|false|null $output): string
    {
        if ($output === null || $output === false || strpos($output, 'Unable to load dynamic library') === false) {
            return (string) $output;
        }

        $hasXdebug = extension_loaded('xdebug');
        $hasXHProf = extension_loaded('xhprof');

        $warningMsg = 'Warning: Some profiling extensions may not be available. ';

        if (! $hasXdebug && ! $hasXHProf) {
            $warningMsg .= 'Neither Xdebug nor XHProf extensions are loaded. ';
        } elseif (! $hasXdebug) {
            $warningMsg .= 'Xdebug extension is not loaded. ';
        } elseif (! $hasXHProf) {
            $warningMsg .= 'XHProf extension is not loaded. ';
        }

        $warningMsg .= "Semantic logging will continue with basic functionality.\n";

        return $warningMsg . $output;
    }

    /** @return string[] */
    private function findNewLogFiles(int $beforeExecution): array
    {
        $pattern = rtrim($this->logDirectory, '/') . '/semantic-log-*.json';
        $files = glob($pattern);
        if ($files === false) {
            return [];
        }

        return array_filter($files, static fn (string $file): bool => filemtime($file) >= $beforeExecution);
    }

    /** @param string[] $newLogFiles */
    private function formatAnalysisResult(array $newLogFiles): string
    {
        usort($newLogFiles, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $executionLog = $newLogFiles[0];

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

    private function getAnalysisPrompt(): string
    {
        return <<<'PROMPT'
This is a SEMANTIC PROFILING log - structured business logic analysis, not low-level Xdebug traces.

SEMANTIC PROFILING focuses on:
- Business workflow analysis (open → events → close patterns)
- Application logic performance (database queries, API calls, cache operations)
- Architectural insights and code quality assessment

IGNORE low-level framework overhead and focus on YOUR APPLICATION CODE.

The log contains schemaUrl fields. Refer to these schemas to understand the semantic meaning of each context:
- business_logic: Core application operations
- database_connection/complex_query: Data access patterns
- external_api_request: Third-party service integrations
- cache_operation: Caching strategy effectiveness
- performance_metrics: Aggregated application metrics

If no performance issues are found, provide:
- What the code is doing (business purpose)
- How it's implemented (technical approach)
- Implementation assessment (is this approach appropriate for the task?)
- Any architectural observations or suggestions for production readiness

Use the semantic profiling data to provide deep insights about code quality and appropriateness, not just performance metrics.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function createErrorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /** @param array<string, mixed> $response */
    private function sendResponse(array $response): void
    {
        $this->debugLog('Sending response', $response);
        echo json_encode($response) . "\n";
        fflush(STDOUT);
    }

    private function sendErrorResponse(mixed $id, int $code, string $message): void
    {
        $response = $this->createErrorResponse($id, $code, $message);
        $this->sendResponse($response);
    }

    private function handleException(Throwable $e, mixed $id): void
    {
        error_log('MCP Server Error: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        $this->sendErrorResponse($id, -32603, 'Internal error: ' . $e->getMessage());
    }

    /** @param array<string, mixed> $data */
    private function debugLog(string $message, array $data = []): void
    {
        if ($this->debugMode) {
            $logData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => $message,
                'data' => $data,
            ];
            error_log('MCP Debug: ' . json_encode($logData, JSON_PRETTY_PRINT));
        }
    }
}
