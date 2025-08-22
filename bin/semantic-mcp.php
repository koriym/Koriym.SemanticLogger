#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Koriym\SemanticLogger\SemanticProfilerMcpServer;

$logDirectory = $argv[1] ?? 'demo';

$server = new SemanticProfilerMcpServer($logDirectory);
fwrite(STDERR, "Semantic Profiler MCP Server started.\n");
$server();
