<?php

declare(strict_types=1);

namespace YourNamespace\McpServer;

use Throwable;

use function feof;
use function fflush;
use function fgets;
use function json_decode;
use function json_encode;
use function json_last_error;
use function trim;
use function error_log;
use function getenv;
use function date;
use function in_array;
use function array_values;

use const STDIN;
use const STDOUT;
use const JSON_ERROR_NONE;
use const JSON_PRETTY_PRINT;

/**
 * Generic MCP Server Skeleton
 *
 * This is a skeleton implementation of an MCP (Model Context Protocol) server in PHP.
 * Extend this class and implement your own tools to create a custom MCP server.
 *
 * @see https://modelcontextprotocol.io/
 */
class GenericMcpServer
{
    /**
     * Tools registry
     * Each tool should have: name, description, inputSchema
     */
    protected array $tools = [];

    /**
     * Resources registry (optional)
     */
    protected array $resources = [];

    /**
     * Prompts registry (optional)
     */
    protected array $prompts = [];

    /**
     * Debug mode flag
     */
    private bool $debugMode = false;

    /**
     * Server information
     */
    private array $serverInfo = [
        'name' => 'generic-mcp-server',
        'version' => '1.0.0',
    ];

    public function __construct()
    {
        $this->debugMode = (bool) (getenv('MCP_DEBUG') ?: false);
        $this->initializeTools();
        $this->initializeResources();
        $this->initializePrompts();
    }

    /**
     * Initialize available tools
     * Override this method to register your custom tools
     */
    protected function initializeTools(): void
    {
        // Example tool registration
        $this->tools = [
            'example_tool' => [
                'name' => 'example_tool',
                'description' => 'An example tool that echoes input',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => [
                            'type' => 'string',
                            'description' => 'Message to echo',
                        ],
                    ],
                    'required' => ['message'],
                ],
            ],
            // Add more tools here...
        ];
    }

    /**
     * Initialize available resources (optional)
     * Override this method to register your resources
     */
    protected function initializeResources(): void
    {
        // Example resource registration
        $this->resources = [
            // 'example_resource' => [
            //     'uri' => 'example://resource',
            //     'name' => 'Example Resource',
            //     'description' => 'An example resource',
            //     'mimeType' => 'text/plain',
            // ],
        ];
    }

    /**
     * Initialize available prompts (optional)
     * Override this method to register your prompts
     */
    protected function initializePrompts(): void
    {
        // Example prompt registration
        $this->prompts = [
            // 'example_prompt' => [
            //     'name' => 'example_prompt',
            //     'description' => 'An example prompt',
            //     'arguments' => [
            //         [
            //             'name' => 'topic',
            //             'description' => 'Topic for the prompt',
            //             'required' => true,
            //         ],
            //     ],
            // ],
        ];
    }

    /**
     * Main entry point - starts the server and listens for requests
     */
    public function __invoke(): void
    {
        try {
            $input = '';

            // Check if STDIN is available
            if (feof(STDIN)) {
                return;
            }

            while (($line = fgets(STDIN)) !== false) {
                $input .= $line;

                if ($this->isCompleteJsonRpc($input)) {
                    $request = json_decode(trim($input), true);

                    if ($request === null) {
                        $this->sendErrorResponse(null, -32700, 'Parse error');
                    } else {
                        $this->debugLog('Received request', $request);

                        try {
                            $response = $this->handleRequest($request);

                            if ($response !== null) {
                                $this->sendResponse($response);
                            }
                        } catch (Throwable $e) {
                            $this->handleException($e, $request['id'] ?? null);
                        }
                    }

                    $input = '';
                }
            }
        } catch (Throwable $e) {
            error_log('MCP Server Fatal Error: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Check if input contains a complete JSON-RPC message
     */
    private function isCompleteJsonRpc(string $input): bool
    {
        $trimmed = trim($input);
        if (empty($trimmed)) {
            return false;
        }

        $decoded = json_decode($trimmed);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Handle incoming JSON-RPC request
     */
    private function handleRequest(array $request): ?array
    {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;

        try {
            switch ($method) {
                case 'initialize':
                    return $this->handleInitialize($id, $params);

                case 'tools/list':
                    return $this->handleToolsList($id);

                case 'tools/call':
                    return $this->handleToolCall($id, $params);

                case 'resources/list':
                    return $this->handleResourcesList($id);

                case 'resources/read':
                    return $this->handleResourceRead($id, $params);

                case 'prompts/list':
                    return $this->handlePromptsList($id);

                case 'prompts/get':
                    return $this->handlePromptGet($id, $params);

                case 'notifications/initialized':
                    // Handle initialized notification (no response needed)
                    return null;

                default:
                    return $this->createErrorResponse($id, -32601, "Method not found: {$method}");
            }
        } catch (Throwable $e) {
            return $this->createErrorResponse($id, -32000, 'Server error: ' . $e->getMessage());
        }
    }

    /**
     * Handle initialize request
     */
    private function handleInitialize(mixed $id, array $params): array
    {
        $clientVersion = $params['protocolVersion'] ?? '2024-11-05';
        $supportedVersions = ['2024-11-05'];

        if (!in_array($clientVersion, $supportedVersions)) {
            $clientVersion = '2024-11-05';
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => $clientVersion,
                'capabilities' => [
                    'tools' => ['listChanged' => true],
                    'resources' => ['subscribe' => false, 'listChanged' => false],
                    'prompts' => ['listChanged' => false],
                ],
                'serverInfo' => $this->serverInfo,
            ],
        ];
    }

    /**
     * Handle tools/list request
     */
    private function handleToolsList(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => array_values($this->tools),
            ],
        ];
    }

    /**
     * Handle tools/call request
     */
    private function handleToolCall(mixed $id, array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

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

    /**
     * Execute a tool
     * Override this method to implement your tool execution logic
     */
    protected function executeTool(string $toolName, array $arguments): string
    {
        switch ($toolName) {
            case 'example_tool':
                return $this->executeExampleTool($arguments);

            default:
                throw new \Exception("Unknown tool: $toolName");
        }
    }

    /**
     * Example tool implementation
     */
    protected function executeExampleTool(array $args): string
    {
        $message = $args['message'] ?? '';
        return "Echo: " . $message;
    }

    /**
     * Handle resources/list request
     */
    private function handleResourcesList(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'resources' => array_values($this->resources),
            ],
        ];
    }

    /**
     * Handle resources/read request
     */
    private function handleResourceRead(mixed $id, array $params): array
    {
        $uri = $params['uri'] ?? '';

        try {
            $content = $this->readResource($uri);

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'text/plain',
                            'text' => $content,
                        ],
                    ],
                ],
            ];
        } catch (Throwable $e) {
            return $this->createErrorResponse($id, -32000, $e->getMessage());
        }
    }

    /**
     * Read a resource
     * Override this method to implement resource reading
     */
    protected function readResource(string $uri): string
    {
        throw new \Exception("Resource not found: $uri");
    }

    /**
     * Handle prompts/list request
     */
    private function handlePromptsList(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'prompts' => array_values($this->prompts),
            ],
        ];
    }

    /**
     * Handle prompts/get request
     */
    private function handlePromptGet(mixed $id, array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        try {
            $prompt = $this->getPrompt($name, $arguments);

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $prompt,
            ];
        } catch (Throwable $e) {
            return $this->createErrorResponse($id, -32000, $e->getMessage());
        }
    }

    /**
     * Get a prompt
     * Override this method to implement prompt generation
     */
    protected function getPrompt(string $name, array $arguments): array
    {
        throw new \Exception("Prompt not found: $name");
    }

    /**
     * Create an error response
     */
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

    /**
     * Send a response to stdout
     */
    private function sendResponse(array $response): void
    {
        $this->debugLog('Sending response', $response);
        echo json_encode($response) . "\n";
        fflush(STDOUT);
    }

    /**
     * Send an error response
     */
    private function sendErrorResponse(mixed $id, int $code, string $message): void
    {
        $response = $this->createErrorResponse($id, $code, $message);
        $this->sendResponse($response);
    }

    /**
     * Handle exceptions
     */
    private function handleException(Throwable $e, mixed $id): void
    {
        error_log('MCP Server Error: ' . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
        $this->sendErrorResponse($id, -32603, 'Internal error: ' . $e->getMessage());
    }

    /**
     * Debug logging
     */
    protected function debugLog(string $message, array $data = []): void
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

// Example usage:
//
// class MyCustomMcpServer extends GenericMcpServer
// {
//     protected function initializeTools(): void
//     {
//         $this->tools = [
//             'my_tool' => [
//                 'name' => 'my_tool',
//                 'description' => 'My custom tool',
//                 'inputSchema' => [
//                     'type' => 'object',
//                     'properties' => [
//                         'param1' => ['type' => 'string'],
//                     ],
//                     'required' => ['param1'],
//                 ],
//             ],
//         ];
//     }
//
//     protected function executeTool(string $toolName, array $arguments): string
//     {
//         switch ($toolName) {
//             case 'my_tool':
//                 return $this->executeMyTool($arguments);
//             default:
//                 throw new \Exception("Unknown tool: $toolName");
//         }
//     }
//
//     private function executeMyTool(array $args): string
//     {
//         // Your tool implementation here
//         return "Result from my tool";
//     }
// }
//
// $server = new MyCustomMcpServer();
// $server();
