#!/usr/bin/env php
<?php

// Try multiple autoloader locations
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
];

$autoloaderFound = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require $autoloadPath;
        $autoloaderFound = true;
        break;
    }
}

if (! $autoloaderFound) {
    fwrite(STDERR, "Error: Could not find composer autoloader.\n");
    exit(1);
}

use Koriym\SemanticLogger\SemanticProfilerMcpServer;

$logDirectory = $argv[1] ?? 'demo';

$server = new SemanticProfilerMcpServer($logDirectory);
fwrite(STDERR, "Semantic Profiler MCP Server started.\n");
$server();
