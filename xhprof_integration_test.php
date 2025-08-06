<?php

require_once 'vendor/autoload.php';

use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\DevLogger;
use Koriym\SemanticLogger\AbstractContext;

// XHProf Profile Context
final class XHProfProfileContext extends AbstractContext
{
    public const TYPE = 'xhprof_profile';
    public const SCHEMA_URL = 'https://example.com/schemas/xhprof_profile.json';
    
    public function __construct(
        public readonly array $profileData,
        public readonly string $runId,
        public readonly float $totalTime,
        public readonly int $totalMemory,
    ) {
    }
}

// Function Performance Context
final class FunctionPerformanceContext extends AbstractContext
{
    public const TYPE = 'function_performance';
    public const SCHEMA_URL = 'https://example.com/schemas/function_performance.json';
    
    public function __construct(
        public readonly string $functionName,
        public readonly int $callCount,
        public readonly float $inclusiveTime,
        public readonly float $exclusiveTime,
        public readonly int $inclusiveMemory,
        public readonly int $exclusiveMemory,
    ) {
    }
}

function simulateXHProfData(): array
{
    // Simulate realistic XHProf data structure
    return [
        'main()' => [
            'ct' => 1,     // call count
            'wt' => 45231, // wall time (microseconds)
            'cpu' => 42180, // CPU time
            'mu' => 2048576, // memory usage
            'pmu' => 2097152, // peak memory usage
        ],
        'mysqli_query' => [
            'ct' => 3,
            'wt' => 142340,
            'cpu' => 5234,
            'mu' => 16384,
            'pmu' => 32768,
        ],
        'curl_exec' => [
            'ct' => 1,
            'wt' => 195420,
            'cpu' => 12450,
            'mu' => 8192,
            'pmu' => 12288,
        ],
        'json_encode' => [
            'ct' => 5,
            'wt' => 1240,
            'cpu' => 1180,
            'mu' => 2048,
            'pmu' => 4096,
        ],
        'Redis::get' => [
            'ct' => 2,
            'wt' => 2340,
            'cpu' => 780,
            'mu' => 1024,
            'pmu' => 2048,
        ],
        'preg_match' => [
            'ct' => 15,
            'wt' => 3420,
            'cpu' => 3200,
            'mu' => 512,
            'pmu' => 1024,
        ],
        'hash_hmac' => [
            'ct' => 8,
            'wt' => 5670,
            'cpu' => 5430,
            'mu' => 256,
            'pmu' => 512,
        ],
    ];
}

function runXHProfIntegrationTest(): void
{
    $logger = new SemanticLogger();
    $devLogger = new DevLogger('/tmp');

    echo "Starting XHProf integration test...\n";

    // Check if XHProf extension is available
    $xhprofAvailable = extension_loaded('xhprof');
    echo "XHProf extension available: " . ($xhprofAvailable ? 'Yes' : 'No') . "\n";

    $runId = uniqid('semantic_', true);
    
    // Start profiling (simulated or real)
    if ($xhprofAvailable) {
        xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY);
        echo "Real XHProf profiling started\n";
    } else {
        echo "Using simulated XHProf data\n";
    }

    $startTime = microtime(true);

    // Main operation
    $operationId = $logger->open(new class() extends AbstractContext {
        public const TYPE = 'profiled_operation';
        public const SCHEMA_URL = 'https://example.com/schemas/profiled_operation.json';
        
        public function __construct(
            public readonly string $operationType = 'user_data_processing',
            public readonly bool $xhprofEnabled = true,
        ) {
        }
    });

    // Simulate work that would generate XHProf data
    simulateWork();

    $endTime = microtime(true);

    // Get profiling data
    if ($xhprofAvailable) {
        $xhprofData = xhprof_disable();
        echo "Real XHProf data collected: " . count($xhprofData) . " function calls\n";
    } else {
        $xhprofData = simulateXHProfData();
        echo "Simulated XHProf data: " . count($xhprofData) . " function calls\n";
    }

    // Calculate totals
    $totalTime = array_sum(array_column($xhprofData, 'wt'));
    $totalMemory = array_sum(array_column($xhprofData, 'mu'));

    // Log XHProf profile data
    $logger->event(new XHProfProfileContext(
        $xhprofData,
        $runId,
        $totalTime / 1000000, // Convert to seconds
        $totalMemory
    ));

    // Log individual function performance for top functions
    $sortedFunctions = $xhprofData;
    uasort($sortedFunctions, fn($a, $b) => $b['wt'] <=> $a['wt']);
    
    $topFunctions = array_slice($sortedFunctions, 0, 5, true);
    
    foreach ($topFunctions as $functionName => $data) {
        $logger->event(new FunctionPerformanceContext(
            $functionName,
            $data['ct'],
            $data['wt'] / 1000000, // Convert to seconds
            $data['wt'] / 1000000, // In this simulation, same as inclusive
            $data['mu'],
            $data['mu'] // In this simulation, same as inclusive
        ));
    }

    // Close operation
    $logger->close(new class($endTime - $startTime, count($xhprofData)) extends AbstractContext {
        public const TYPE = 'profiling_complete';
        public const SCHEMA_URL = 'https://example.com/schemas/profiling_complete.json';
        
        public function __construct(
            public readonly float $totalExecutionTime,
            public readonly int $functionsCalled,
        ) {
        }
    }, $operationId);

    // Save to file
    $devLogger->log($logger);
    
    echo "XHProf integration test completed!\n";
    echo "Run ID: $runId\n";
    echo "Total functions profiled: " . count($xhprofData) . "\n";
    echo "Execution time: " . number_format(($endTime - $startTime) * 1000, 2) . "ms\n";
}

function simulateWork(): void
{
    // Simulate database queries
    for ($i = 0; $i < 3; $i++) {
        usleep(47000); // ~47ms per query
    }
    
    // Simulate external API call
    usleep(195000); // ~195ms
    
    // Simulate JSON processing
    $data = range(1, 1000);
    for ($i = 0; $i < 5; $i++) {
        json_encode($data);
    }
    
    // Simulate cache operations
    usleep(2000); // 2ms
    
    // Simulate regex operations
    $text = str_repeat('hello world test string ', 100);
    for ($i = 0; $i < 15; $i++) {
        preg_match('/test/', $text);
    }
    
    // Simulate hash operations
    for ($i = 0; $i < 8; $i++) {
        hash_hmac('sha256', 'data' . $i, 'secret_key');
    }
}

echo "=== XHProf Integration Test ===\n\n";
runXHProfIntegrationTest();
echo "\nCheck /tmp for semantic-dev-*.json files with XHProf data.\n";