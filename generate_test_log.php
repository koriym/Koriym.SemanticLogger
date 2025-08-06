<?php

require_once 'vendor/autoload.php';

use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\DevLogger;
use Koriym\SemanticLogger\AbstractContext;

// Test context
final class TestContext extends AbstractContext
{
    public const TYPE = 'test_operation';
    public const SCHEMA_URL = 'https://example.com/schemas/test.json';
    
    public function __construct(
        public readonly string $operation,
        public readonly int $value,
    ) {
    }
}

// Generate test logs in /tmp for Claude Desktop
$logger = new SemanticLogger();
$devLogger = new DevLogger('/tmp');

echo "Generating test semantic logs...\n";

// Generate a few test logs
for ($i = 1; $i <= 3; $i++) {
    $logger = new SemanticLogger();
    $openId = $logger->open(new TestContext("test_operation_$i", $i * 100));
    $logger->event(new TestContext("processing_$i", $i * 50));
    $logger->close(new TestContext("completed_$i", $i * 150), $openId);
    
    $devLogger->log($logger);
    echo "Generated log $i\n";
    
    // Small delay to ensure different timestamps
    usleep(100000); // 0.1 second
}

echo "Test logs generated successfully!\n";
echo "Check /tmp for semantic-dev-*.json files\n";