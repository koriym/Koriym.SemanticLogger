<?php

require_once 'vendor/autoload.php';

use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\DevLogger;
use Koriym\SemanticLogger\AbstractContext;

// Simple test context
final class XdebugTestContext extends AbstractContext
{
    public const TYPE = 'xdebug_profiling_test';
    public const SCHEMA_URL = 'https://example.com/schemas/xdebug_test.json';
    
    public function __construct(
        public readonly string $operation,
        public readonly bool $xdebugEnabled = false,
    ) {
    }
}

echo "=== Xdebug Profile Test ===\n";
echo "XDEBUG_MODE: " . (getenv('XDEBUG_MODE') ?: 'not set') . "\n";
echo "Xdebug extension loaded: " . (extension_loaded('xdebug') ? 'Yes' : 'No') . "\n";

if (extension_loaded('xdebug')) {
    echo "Xdebug version: " . phpversion('xdebug') . "\n";
    echo "Xdebug modes: " . (ini_get('xdebug.mode') ?: 'default') . "\n";
}

$logger = new SemanticLogger();
$devLogger = new DevLogger('/tmp');

$startTime = microtime(true);

// Start operation with Xdebug info
$operationId = $logger->open(new XdebugTestContext(
    'performance_test',
    extension_loaded('xdebug')
));

// Simulate some work to generate profiling data
function slowFunction(): int
{
    $result = 0;
    for ($i = 0; $i < 10000; $i++) {
        $result += sqrt($i) * sin($i / 100);
    }
    return (int) $result;
}

function anotherSlowFunction(): array
{
    $data = [];
    for ($i = 0; $i < 1000; $i++) {
        $data[] = [
            'id' => $i,
            'value' => md5((string) $i),
            'timestamp' => time() + $i
        ];
    }
    return $data;
}

// Execute slow functions
$result1 = slowFunction();
$result2 = anotherSlowFunction();

$endTime = microtime(true);
$executionTime = $endTime - $startTime;

// Log the results
$logger->event(new class($executionTime, count($result2)) extends AbstractContext {
    public const TYPE = 'performance_result';
    public const SCHEMA_URL = 'https://example.com/schemas/performance_result.json';
    
    public function __construct(
        public readonly float $executionTime,
        public readonly int $dataCount,
    ) {
    }
});

// Close with final context
$logger->close(new XdebugTestContext(
    'performance_test_completed',
    extension_loaded('xdebug')
), $operationId);

// Save log
$devLogger->log($logger);

echo "Execution time: " . number_format($executionTime * 1000, 2) . "ms\n";
echo "Slow function result: $result1\n";
echo "Data array count: " . count($result2) . "\n";

// Check for Xdebug trace files
if (extension_loaded('xdebug') && getenv('XDEBUG_MODE') === 'trace') {
    $traceDir = ini_get('xdebug.output_dir') ?: sys_get_temp_dir();
    $traceFiles = glob($traceDir . '/trace.*');
    if ($traceFiles) {
        echo "Xdebug trace files generated: " . count($traceFiles) . "\n";
        echo "Latest trace: " . end($traceFiles) . "\n";
    } else {
        echo "No Xdebug trace files found in: $traceDir\n";
    }
}

echo "Test completed! Check /tmp for semantic log with Xdebug info.\n";