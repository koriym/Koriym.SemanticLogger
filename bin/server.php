#!/usr/bin/env php
<?php

/**
 * Semantic Profiler MCP Server
 *
 * AI-powered performance analysis through structured semantic profiling.
 * Fulfilling the vision of Semantic Web - machines understanding meaning.
 */

declare(strict_types=1);

use function json_decode;
use function json_encode;

/**
 * MCP Server Types
 *
 * @psalm-type McpJsonRpcError = array{
 *     code: int,
 *     message: string
 * }
 * @psalm-type McpServerInfo = array{
 *     name: string,
 *     version: string
 * }
 * @psalm-type McpCapabilities = array{
 *     tools: object
 * }
 * @psalm-type McpInitializeResult = array{
 *     protocolVersion: string,
 *     serverInfo: McpServerInfo,
 *     capabilities: McpCapabilities
 * }
 * @psalm-type McpPropertySchema = array{
 *     type: string,
 *     description?: string,
 *     default?: string
 * }
 * @psalm-type McpInputSchema = array{
 *     type: string,
 *     properties: object|array<string, McpPropertySchema>,
 *     required?: list<string>
 * }
 * @psalm-type McpTool = array{
 *     name: string,
 *     description: string,
 *     inputSchema: McpInputSchema
 * }
 * @psalm-type McpToolsList = list<McpTool>
 * @psalm-type McpToolsListResult = array{
 *     tools: McpToolsList
 * }
 * @psalm-type McpContent = array{
 *     type: string,
 *     text: string
 * }
 * @psalm-type McpContentList = list<McpContent>
 * @psalm-type McpToolCallResult = array{
 *     content: McpContentList,
 *     isError: bool
 * }
 * @psalm-type McpJsonRpcResponse = array{
 *     jsonrpc: string,
 *     id: int|string|null,
 *     result?: McpInitializeResult|McpToolsListResult|McpToolCallResult,
 *     error?: McpJsonRpcError
 * }
 * @psalm-type McpJsonRpcRequest = array{
 *     jsonrpc: string,
 *     method: string,
 *     id: int|string|null,
 *     params?: array<string, mixed>
 * }
 * @psalm-type McpToolCallParams = array{
 *     name?: string,
 *     arguments?: array<string, mixed>
 * }
 * @psalm-type McpSemanticAnalyzeArgs = array{
 *     script?: string,
 *     xdebug_mode?: string
 * }
 * @psalm-type McpLogData = array<string, mixed>
 */


$logDirectory = $argv[1] ?? null;

if ($logDirectory === null || $logDirectory === '') {
    fwrite(STDERR, "Usage: php server.php <log-directory>\n");
    exit(1);
}

if (! is_dir($logDirectory)) {
    fwrite(STDERR, "Directory not found: $logDirectory\n");
    exit(1);
}

// Find the latest semantic log file
$pattern = rtrim($logDirectory, '/') . '/semantic-dev-*.json';
$files = glob($pattern);

if ($files === false || empty($files)) {
    fwrite(STDERR, "No semantic log files found in directory: $logDirectory\n");
    exit(1);
}

// Sort by modification time, get the latest
usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
$logFile = $files[0];

fwrite(STDERR, "Using latest log file: $logFile\n");

// Make log directory available globally for runAndAnalyze function
$GLOBALS['logDirectory'] = $logDirectory;

// Handle JSON-RPC requests from STDIN
while ($line = fgets(STDIN)) {
    $request = json_decode(trim($line), true);

    if (! is_array($request) || 
        ! isset($request['jsonrpc'], $request['method']) || 
        ! array_key_exists('id', $request)) {
        // Log the malformed request
        fwrite(STDERR, "Malformed JSON-RPC request: " . trim($line) . "\n");

        // Prepare JSON-RPC error response
        $errorResponse = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32600,
                'message' => 'Invalid Request',
            ],
            'id' => is_array($request) && array_key_exists('id', $request) ? $request['id'] : null,
        ];
        $encodedError = json_encode($errorResponse);
        if ($encodedError !== false) {
            fwrite(STDOUT, $encodedError . "\n");
        }
        continue;
    }

    /** @var McpJsonRpcRequest $request */
    
    $params = $request['params'] ?? null;
    /** @var McpToolCallParams $toolCallParams */
    $toolCallParams = is_array($params) ? $params : [];
    
    /** @var McpJsonRpcResponse|null $response */
    $response = match ($request['method'] ?? '') {
        'initialize' => [
            'jsonrpc' => '2.0',
            'id' => $request['id'],
            'result' => [
                'protocolVersion' => '2024-11-05',
                'serverInfo' => ['name' => 'semantic-profiler', 'version' => '1.0.0'],
                'capabilities' => [
                    'tools' => (object) [],
                ],
            ],
        ],
        'tools/list' => [
            'jsonrpc' => '2.0',
            'id' => $request['id'],
            'result' => [
                'tools' => [
                    [
                        'name' => 'getSemanticProfile',
                        'description' => 'Retrieve latest semantic performance profile - structured data designed for AI understanding and insight generation',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => (object) [],
                        ],
                    ],
                    [
                        'name' => 'listSemanticProfiles',
                        'description' => 'List all available semantic profile log files in the directory',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => (object) [],
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
                                    'description' => 'Xdebug mode (default: trace)',
                                    'default' => 'trace',
                                ],
                            ],
                            'required' => ['script'],
                        ],
                    ],
                ],
            ],
        ],
        'tools/call' => [
            'jsonrpc' => '2.0',
            'id' => $request['id'],
            'result' => handleToolCall($toolCallParams, $logFile),
        ],
        default => (
            // If 'id' is not set or is null, it's a notification: do not return an error response
            (!array_key_exists('id', $request) || $request['id'] === null)
                ? null
                : [
                    'jsonrpc' => '2.0',
                    'id' => $request['id'],
                    'error' => [
                        'code' => -32601,
                        'message' => 'Method not found',
                    ],
                ]
        )
    };

    // Don't send response for notifications (null responses)
    if ($response === null) {
        continue;
    }

    $encoded = json_encode($response);
    if ($encoded === false) {
        $jsonError = json_last_error_msg();
        fwrite(STDERR, "json_encode failed: $jsonError\n");
    } else {
        fwrite(STDOUT, $encoded . "\n");
    }
}

/**
 * @return McpLogData
 */
function getLog(string $file): array
{
    $content = file_get_contents($file);
    
    if ($content === false) {
        return ['error' => 'Failed to read file'];
    }

    /** @var mixed $decoded */
    $decoded = json_decode($content, true);
    /** @var McpLogData $result */
    $result = is_array($decoded) ? $decoded : ['error' => 'Invalid log format'];
    return $result;
}

/**
 * @param McpToolCallParams $params
 * @return McpToolCallResult
 */
function handleToolCall(array $params, string $logFile): array
{
    $toolName = $params['name'] ?? '';

    if ($toolName === 'getSemanticProfile') {
        $logData = getLog($logFile);
        $analysisPrompt = getAnalysisPrompt();

        $jsonData = json_encode($logData, JSON_PRETTY_PRINT);
        assert(is_string($jsonData));
        
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $analysisPrompt . "\n\n```json\n" . $jsonData . "\n```",
                ],
            ],
            'isError' => false,
        ];
    }

    if ($toolName === 'listSemanticProfiles') {
        return listSemanticProfiles();
    }

    if ($toolName === 'semanticAnalyze') {
        /** @var McpSemanticAnalyzeArgs $args */
        $args = $params['arguments'] ?? [];
        return semanticAnalyze($args);
    }

    return [
        'content' => [
            [
                'type' => 'text',
                'text' => 'Unknown tool: ' . $toolName,
            ],
        ],
        'isError' => true,
    ];
}

/**
 * @param McpSemanticAnalyzeArgs $args
 * @return McpToolCallResult
 */
function semanticAnalyze(array $args): array
{
    $script = $args['script'] ?? '';
    // Validate and sanitize xdebug mode to prevent command injection
    $allowedModes = ['trace', 'profile', 'debug', 'develop', 'gcstats', 'off'];
    $requestedMode = $args['xdebug_mode'] ?? 'trace';
    $xdebugMode = in_array($requestedMode, $allowedModes, true) ? $requestedMode : 'trace';

    if (! $script) {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Error: script parameter is required',
                ],
            ],
            'isError' => true,
        ];
    }

    if (! file_exists($script)) {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => "Error: Script not found: $script",
                ],
            ],
            'isError' => true,
        ];
    }

    // Record time before execution to find newly created log files
    $beforeExecution = time();

    // Execute the PHP script with profiling settings via -d options
    // All variables are validated and properly escaped to prevent command injection
    $escapedXdebugMode = escapeshellarg($xdebugMode);
    $escapedScript = escapeshellarg($script);
    $escapedXdebugConfig = escapeshellarg('compression_level=0');
    $logDirectory = $GLOBALS['logDirectory'];
    assert(is_string($logDirectory));
    $phpOptions = [
        'zend_extension=xdebug.so',
        'extension=xhprof.so',
        'max_execution_time=30',
        'memory_limit=256M',
        "xdebug.output_dir=$logDirectory",
        'xdebug.start_with_request=no',
        'xdebug.trace_format=1',
        'xdebug.trace_options=10',
        'xdebug.use_compression=0',
        'xdebug.collect_params=1',
        'xdebug.collect_return=1',
        'xdebug.collect_assignments=1',
        'error_reporting=E_ALL',
        'display_errors=1',
    ];
    $phpOptionsString = implode(' ', array_map(fn($opt) => "-d $opt", $phpOptions));
    $command = "XDEBUG_MODE=$escapedXdebugMode XDEBUG_CONFIG=$escapedXdebugConfig php $phpOptionsString $escapedScript 2>&1";

    /** @psalm-suppress ForbiddenCode */
    $output = shell_exec($command);

    // Find semantic log files created during script execution
    $logDirectory = $GLOBALS['logDirectory'];
    assert(is_string($logDirectory));
    $pattern = rtrim($logDirectory, '/') . '/semantic-dev-*.json';

    $files = glob($pattern);
    if ($files === false) {
        $files = [];
    }
    $newLogFiles = array_filter($files, static fn (string $file): bool => filemtime($file) >= $beforeExecution);

    if (empty($newLogFiles)) {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => "Script executed but no new semantic log generated.\nOutput:\n$output",
                ],
            ],
            'isError' => false,
        ];
    }

    // Get the newest log file from this execution
    usort($newLogFiles, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $executionLog = $newLogFiles[0];

    // Load and return the log data with analysis prompt
    $logData = getLog($executionLog);
    $analysisPrompt = getAnalysisPrompt();

    $jsonData = json_encode($logData, JSON_PRETTY_PRINT);
    assert(is_string($jsonData));
    
    return [
        'content' => [
            [
                'type' => 'text',
                'text' => "Script executed successfully.\nLog file: $executionLog\n\n" . $analysisPrompt . "\n\n```json\n" . $jsonData . "\n```",
            ],
        ],
        'isError' => false,
    ];
}

/**
 * @return McpToolCallResult
 */
function listSemanticProfiles(): array
{
    $logDirectory = $GLOBALS['logDirectory'];
    assert(is_string($logDirectory));
    $pattern = rtrim($logDirectory, '/') . '/semantic-dev-*.json';
    
    $files = glob($pattern);
    if ($files === false || empty($files)) {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'No semantic profile files found in directory: ' . $logDirectory,
                ],
            ],
            'isError' => false,
        ];
    }
    
    // Sort by modification time (newest first)
    usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
    
    $fileList = [];
    foreach ($files as $file) {
        $basename = basename($file);
        $size = filesize($file);
        $mtime = filemtime($file);
        $fileList[] = sprintf(
            '• %s (%s bytes, %s)',
            $basename,
            number_format($size),
            date('Y-m-d H:i:s', $mtime)
        );
    }
    
    $text = "Available semantic profile files:\n\n" . implode("\n", $fileList);
    
    return [
        'content' => [
            [
                'type' => 'text',
                'text' => $text,
            ],
        ],
        'isError' => false,
    ];
}

/**
 * Generate analysis prompt for AI-native semantic web processing
 */
function getAnalysisPrompt(): string
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
