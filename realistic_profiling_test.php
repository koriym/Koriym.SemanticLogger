<?php

require_once 'vendor/autoload.php';

use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\DevLogger;
use Koriym\SemanticLogger\AbstractContext;

// Realistic API Context
final class ApiRequestContext extends AbstractContext
{
    public const TYPE = 'api_request';
    public const SCHEMA_URL = 'https://example.com/schemas/api_request.json';
    
    public function __construct(
        public readonly string $endpoint,
        public readonly string $method,
        public readonly array $headers,
    ) {
    }
}

// Database Query Context
final class DatabaseQueryContext extends AbstractContext
{
    public const TYPE = 'database_query';
    public const SCHEMA_URL = 'https://example.com/schemas/database_query.json';
    
    public function __construct(
        public readonly string $query,
        public readonly array $params,
        public readonly float $executionTime,
        public readonly int $rowCount,
    ) {
    }
}

// Performance Context with real metrics
final class PerformanceContext extends AbstractContext
{
    public const TYPE = 'performance_metrics';
    public const SCHEMA_URL = 'https://example.com/schemas/performance.json';
    
    public function __construct(
        public readonly float $executionTime,
        public readonly int $memoryUsage,
        public readonly int $peakMemory,
        public readonly array $functionCalls,
    ) {
    }
}

// Cache Context
final class CacheContext extends AbstractContext
{
    public const TYPE = 'cache_operation';
    public const SCHEMA_URL = 'https://example.com/schemas/cache.json';
    
    public function __construct(
        public readonly string $operation,
        public readonly string $key,
        public readonly bool $hit,
        public readonly float $latency,
    ) {
    }
}

// External Service Context
final class ExternalServiceContext extends AbstractContext
{
    public const TYPE = 'external_service';
    public const SCHEMA_URL = 'https://example.com/schemas/external_service.json';
    
    public function __construct(
        public readonly string $serviceName,
        public readonly string $endpoint,
        public readonly int $responseCode,
        public readonly float $responseTime,
        public readonly int $payloadSize,
    ) {
    }
}

// Simulate realistic application workflow
function simulateRealisticWorkflow(): void
{
    $logger = new SemanticLogger();
    $devLogger = new DevLogger('/tmp');

    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    // 1. API Request starts
    $apiRequestId = $logger->open(new ApiRequestContext(
        '/api/users/search',
        'POST',
        ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ***']
    ));

    // Simulate some processing time
    usleep(50000); // 50ms

    // 2. Cache check
    $logger->event(new CacheContext(
        'get',
        'user_search_popular_terms',
        false, // cache miss
        0.002 // 2ms
    ));

    // 3. Database query for search
    usleep(150000); // 150ms - simulate DB query time
    $queryTime = microtime(true);
    
    $logger->event(new DatabaseQueryContext(
        'SELECT u.id, u.name, u.email FROM users u WHERE u.name LIKE ? AND u.active = 1 ORDER BY u.created_at DESC LIMIT 20',
        ['%john%'],
        0.143, // 143ms execution time
        15     // 15 rows returned
    ));

    // 4. External service call (avatar service)
    usleep(200000); // 200ms
    $logger->event(new ExternalServiceContext(
        'AvatarService',
        'https://avatars.example.com/api/batch',
        200,
        0.195, // 195ms response time
        2048   // 2KB payload
    ));

    // 5. Cache store operation
    $logger->event(new CacheContext(
        'set',
        'user_search_results_john',
        true, // successful store
        0.001 // 1ms
    ));

    // 6. Performance metrics at the end
    $endTime = microtime(true);
    $endMemory = memory_get_usage();
    $peakMemory = memory_get_peak_usage();

    $logger->event(new PerformanceContext(
        $endTime - $startTime,                    // Total execution time
        $endMemory - $startMemory,                // Memory used
        $peakMemory,                             // Peak memory
        [                                        // Function call statistics (simulated)
            'mysqli_query' => ['calls' => 3, 'time' => 0.143],
            'curl_exec' => ['calls' => 1, 'time' => 0.195],
            'redis_get' => ['calls' => 2, 'time' => 0.003],
            'json_encode' => ['calls' => 5, 'time' => 0.001],
        ]
    ));

    // 7. Close with final result
    $finalContext = new class($endTime - $startTime, 15) extends AbstractContext {
        public const TYPE = 'api_response';
        public const SCHEMA_URL = 'https://example.com/schemas/api_response.json';
        
        public function __construct(
            public readonly float $totalTime,
            public readonly int $resultCount,
        ) {
        }
    };

    $logger->close($finalContext, $apiRequestId);

    // Output to file
    $devLogger->log($logger);
    
    echo "Realistic profiling test completed!\n";
    echo "Total time: " . number_format(($endTime - $startTime) * 1000, 2) . "ms\n";
    echo "Memory used: " . number_format(($endMemory - $startMemory) / 1024, 2) . "KB\n";
    echo "Peak memory: " . number_format($peakMemory / 1024, 2) . "KB\n";
}

// Heavy computation simulation
function simulateHeavyComputation(): void
{
    $logger = new SemanticLogger();
    $devLogger = new DevLogger('/tmp');

    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    // Heavy computation operation
    $computationId = $logger->open(new class() extends AbstractContext {
        public const TYPE = 'heavy_computation';
        public const SCHEMA_URL = 'https://example.com/schemas/computation.json';
        
        public function __construct(
            public readonly string $algorithm = 'data_processing',
            public readonly int $dataSize = 10000,
        ) {
        }
    });

    // Simulate CPU-intensive work
    $data = [];
    for ($i = 0; $i < 10000; $i++) {
        $data[] = sqrt($i) * sin($i) + cos($i * 2);
        
        // Log progress every 2500 iterations
        if ($i % 2500 === 0 && $i > 0) {
            $currentTime = microtime(true);
            $currentMemory = memory_get_usage();
            
            $logger->event(new PerformanceContext(
                $currentTime - $startTime,
                $currentMemory - $startMemory,
                memory_get_peak_usage(),
                [
                    'sqrt' => ['calls' => $i, 'time' => ($currentTime - $startTime) * 0.3],
                    'sin' => ['calls' => $i, 'time' => ($currentTime - $startTime) * 0.3],
                    'cos' => ['calls' => $i, 'time' => ($currentTime - $startTime) * 0.3],
                ]
            ));
        }
    }

    $endTime = microtime(true);
    $endMemory = memory_get_usage();

    $logger->close(new PerformanceContext(
        $endTime - $startTime,
        $endMemory - $startMemory,
        memory_get_peak_usage(),
        [
            'total_operations' => ['calls' => 10000, 'time' => $endTime - $startTime],
            'sqrt' => ['calls' => 10000, 'time' => ($endTime - $startTime) * 0.3],
            'sin' => ['calls' => 10000, 'time' => ($endTime - $startTime) * 0.3],
            'cos' => ['calls' => 10000, 'time' => ($endTime - $startTime) * 0.4],
        ]
    ), $computationId);

    $devLogger->log($logger);
    
    echo "Heavy computation test completed!\n";
    echo "Processing time: " . number_format(($endTime - $startTime) * 1000, 2) . "ms\n";
    echo "Data processed: " . count($data) . " items\n";
}

echo "=== Realistic Profiling Tests ===\n\n";

echo "1. Running realistic API workflow simulation...\n";
simulateRealisticWorkflow();
echo "\n";

echo "2. Running heavy computation simulation...\n";
simulateHeavyComputation();
echo "\n";

echo "All realistic profiling tests completed!\n";
echo "Check /tmp for semantic-dev-*.json files with realistic performance data.\n";