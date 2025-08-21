#!/usr/bin/env php
<?php

/**
 * Semantic Profiler MCP Server
 *
 * AI-powered performance analysis through structured semantic profiling.
 * Fulfilling the vision of Semantic Web - machines understanding meaning.
 */

declare(strict_types=1);

// Load autoloader - check multiple possible locations
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$autoloaderFound = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    fwrite(STDERR, "Error: Could not find composer autoloader. Please run 'composer install'.\n");
    exit(1);
}

use Koriym\SemanticLogger\SemanticProfilerMcpServer;

$logDirectory = $argv[1] ?? null;

if ($logDirectory === null || $logDirectory === '') {
    fwrite(STDERR, "Usage: php semantic-mcp.php <log-directory>\n");
    exit(1);
}

try {
    // Create and run the MCP server
    $server = new SemanticProfilerMcpServer($logDirectory);
    fwrite(STDERR, "Semantic Profiler MCP Server started with log directory: $logDirectory\n");
    $server();
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
} catch (\Exception $e) {
    fwrite(STDERR, "Fatal error: " . $e->getMessage() . "\n");
    exit(1);
}
