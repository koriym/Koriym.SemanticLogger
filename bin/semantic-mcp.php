#!/usr/bin/env php
<?php

require __DIR__ . '/autoload.php';

use Koriym\SemanticLogger\McpServer;

$logDirectory = $argv[1] ?? 'demo';

$server = new McpServer($logDirectory);
fwrite(STDERR, "Semantic Profiler MCP Server started.\n");
$server();
