<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/ComplexWebRequestContexts.php';

/**
 * Realistic performance problem scenarios with strategic sleeps
 * 
 * These scenarios simulate common real-world performance bottlenecks:
 * - N+1 query problems
 * - Slow external APIs
 * - Heavy file processing
 * - Cache misses
 * - Inefficient algorithms
 */
class SlowPerformanceScenarios
{
    private SemanticLogger $logger;
    private DevLogger $devLogger;

    public function __construct()
    {
        $this->logger = new SemanticLogger();
        $this->devLogger = new DevLogger('/tmp');
    }

    public function simulateN1QueryProblem(): void
    {
        echo "=== N+1 Query Problem Simulation ===\n";

        $startTime = microtime(true);

        // Main HTTP request
        $httpRequestId = $this->logger->open(new HttpRequestContext(
            'GET',
            '/api/users/dashboard',
            ['Authorization' => 'Bearer <TOKEN_REDACTED>'],
            null,
            'Dashboard/1.0',
            '192.168.1.50'
        ));

        // Authentication - fast
        $authId = $this->logger->open(new AuthenticationContext('JWT', null, [], false, 0.0));
        usleep(5000); // 5ms - reasonable auth time
        $this->logger->close(new AuthenticationContext('JWT', 'user_789', ['role' => 'user'], true, 0.005), $authId);

        // Business logic start
        $businessId = $this->logger->open(new BusinessLogicContext(
            'dashboard_data_collection',
            ['userId' => 789],
            [],
            [],
            false
        ));

        // Database connection - reasonable
        $dbId = $this->logger->open(new DatabaseConnectionContext('mysql', 'db.example.com', 'app_db', 0.02, true));

        // THE PROBLEM: Main query to get user posts (fast)
        $this->logger->event(new ComplexQueryContext(
            'SELECT',
            'posts',
            ['user_id' => 789, 'status' => 'published'],
            2,
            0.012, // Fast main query
            50,    // 50 posts found
            false
        ));

        // THE BIG PROBLEM: N+1 queries - getting author info for each post (SLOW!)
        for ($i = 1; $i <= 50; $i++) {
            // Each individual query is reasonably fast, but 50 of them...
            usleep(8000); // 8ms per query
            $this->logger->event(new ComplexQueryContext(
                'SELECT',
                'users',
                ['id' => $i + 1000], // Different author IDs
                1,
                0.008,
                1,
                false
            ));

            // Simulate some processing time between queries
            usleep(1000); // 1ms processing per iteration
        }

        $this->logger->close(new DatabaseConnectionContext('mysql', 'db.example.com', 'app_db', 0.02, true), $dbId);

        // Business logic completion
        $endTime = microtime(true);
        $this->logger->close(new BusinessLogicContext(
            'dashboard_data_collection',
            ['userId' => 789],
            ['posts' => 50, 'totalQueries' => 51], // 1 main + 50 N+1 queries
            [],
            true
        ), $businessId);

        // Performance metrics showing the problem
        $this->logger->event(new PerformanceMetricsContext(
            $endTime - $startTime,
            memory_get_usage() - memory_get_usage(),
            memory_get_peak_usage(),
            51, // Total queries - THE PROBLEM!
            0.450, // Total query time (50 * 0.009ms)
            0, // No cache hits
            0,
            [
                'mysql_query' => ['calls' => 51, 'time' => 0.450], // Excessive queries
                'json_encode' => ['calls' => 50, 'time' => 0.005],
            ]
        ));

        $this->logger->close(new HttpResponseContext(
            200,
            ['Content-Type' => 'application/json'],
            1024,
            'application/json',
            $endTime - $startTime, // Very slow response time
            false
        ), $httpRequestId);

        $this->devLogger->log($this->logger);

        echo "N+1 Query Problem completed!\n";
        echo "Total time: " . number_format(($endTime - $startTime) * 1000, 0) . "ms (VERY SLOW!)\n";
        echo "Database queries: 51 (1 main + 50 N+1) - THIS IS THE PROBLEM!\n\n";
    }

    public function simulateSlowExternalAPI(): void
    {
        echo "=== Slow External API Simulation ===\n";

        $startTime = microtime(true);

        $httpRequestId = $this->logger->open(new HttpRequestContext(
            'POST',
            '/api/send-notification',
            ['Content-Type' => 'application/json'],
            ['message' => 'Important update', 'recipients' => 100],
            'NotificationApp/2.0',
            '10.0.1.20'
        ));

        // Fast authentication
        $authId = $this->logger->open(new AuthenticationContext('API_KEY', null, [], false, 0.0));
        usleep(3000); // 3ms
        $this->logger->close(new AuthenticationContext('API_KEY', 'service_account', [], true, 0.003), $authId);

        // Business logic
        $businessId = $this->logger->open(new BusinessLogicContext(
            'bulk_notification_sending',
            ['recipients' => 100],
            [],
            [],
            false
        ));

        // Fast database operations
        $dbId = $this->logger->open(new DatabaseConnectionContext('postgres', 'db.example.com', 'notifications', 0.015, true));
        
        usleep(20000); // 20ms for recipient validation
        $this->logger->event(new ComplexQueryContext(
            'SELECT',
            'users',
            ['active' => true, 'notifications_enabled' => true],
            2,
            0.020,
            98, // 2 users have notifications disabled
            true,
            'active_users_cache'
        ));

        $this->logger->close(new DatabaseConnectionContext('postgres', 'db.example.com', 'notifications', 0.015, true), $dbId);

        // THE PROBLEM: Multiple slow external API calls
        $externalServices = [
            ['EmailService', 'https://api.email-slow.com/v1/send', 45], // 45 recipients
            ['SMSService', 'https://api.sms-timeout.com/v2/bulk', 30],  // 30 recipients  
            ['PushService', 'https://push.very-slow.io/notify', 23],     // 23 recipients
        ];

        foreach ($externalServices as [$service, $endpoint, $count]) {
            $serviceId = $this->logger->open(new ExternalApiContext(
                $service,
                $endpoint,
                'POST',
                0,
                0.0,
                2048,
                0
            ));

            // SIMULATE SLOW EXTERNAL APIs - more realistic timing
            if ($service === 'EmailService') {
                usleep(800000); // 800ms - slow but realistic
                $statusCode = 200;
                $responseTime = 0.800;
            } elseif ($service === 'SMSService') {
                usleep(1200000); // 1.2 seconds - slower
                $statusCode = 202;
                $responseTime = 1.200;
            } else { // PushService
                usleep(600000); // 600ms - moderately slow
                $statusCode = 200;
                $responseTime = 0.600;
            }

            $this->logger->close(new ExternalApiContext(
                $service,
                $endpoint,
                'POST',
                $statusCode,
                $responseTime,
                2048,
                512
            ), $serviceId);

            echo "  $service: $count notifications sent in " . number_format($responseTime * 1000, 0) . "ms\n";
        }

        $endTime = microtime(true);

        $this->logger->close(new BusinessLogicContext(
            'bulk_notification_sending',
            ['recipients' => 100],
            ['sent' => 98, 'failed' => 2],
            [],
            true
        ), $businessId);

        // Performance metrics showing external API bottleneck
        $this->logger->event(new PerformanceMetricsContext(
            $endTime - $startTime,
            memory_get_usage() - memory_get_usage(),
            memory_get_peak_usage(),
            1, // Only 1 database query
            0.020, // Fast database
            1, // Cache hit
            0,
            [
                'curl_exec' => ['calls' => 3, 'time' => 8.500], // THE BOTTLENECK!
                'postgres_query' => ['calls' => 1, 'time' => 0.020],
            ]
        ));

        $this->logger->close(new HttpResponseContext(
            200,
            ['Content-Type' => 'application/json'],
            256,
            'application/json',
            $endTime - $startTime,
            false
        ), $httpRequestId);

        $this->devLogger->log($this->logger);

        echo "Slow External API simulation completed!\n";
        echo "Total time: " . number_format(($endTime - $startTime) * 1000, 0) . "ms (EXTREMELY SLOW!)\n";
        echo "External API time: ~8.5 seconds - THIS IS THE BOTTLENECK!\n\n";
    }

    public function simulateHeavyFileProcessing(): void
    {
        echo "=== Heavy File Processing Simulation ===\n";

        $startTime = microtime(true);

        $httpRequestId = $this->logger->open(new HttpRequestContext(
            'POST',
            '/api/process-video',
            ['Content-Type' => 'multipart/form-data'],
            null,
            'VideoProcessor/1.0',
            '172.16.1.100'
        ));

        // Fast auth
        $authId = $this->logger->open(new AuthenticationContext('Bearer', null, [], false, 0.0));
        usleep(4000); // 4ms
        $this->logger->close(new AuthenticationContext('Bearer', 'video_user_456', ['plan' => 'premium'], true, 0.004), $authId);

        // File upload processing
        $uploadId = $this->logger->open(new FileProcessingContext(
            'video_upload',
            'user_video_large.mp4',
            'video/mp4',
            157286400, // 150MB file
            0.0,
            false
        ));

        // SLOW: File validation and processing
        usleep(800000); // 800ms to validate large video file
        $this->logger->event(new PerformanceMetricsContext(
            0.8,
            50 * 1024 * 1024, // 50MB memory for file processing
            75 * 1024 * 1024, // 75MB peak memory
            0,
            0.0,
            0,
            0,
            [
                'file_validation' => ['calls' => 1, 'time' => 0.8],
                'mime_detection' => ['calls' => 1, 'time' => 0.05],
            ]
        ));

        $this->logger->close(new FileProcessingContext(
            'video_upload',
            'user_video_large.mp4',
            'video/mp4',
            157286400,
            0.8,
            true,
            '/tmp/uploads/video_12345.mp4'
        ), $uploadId);

        // THE MAJOR BOTTLENECK: Video transcoding
        $transcodingId = $this->logger->open(new FileProcessingContext(
            'video_transcoding',
            'video_12345.mp4',
            'video/mp4',
            157286400,
            0.0,
            false
        ));

        echo "  Starting video transcoding (realistic slow processing)...\n";
        
        // Simulate multiple transcoding steps - more realistic timing
        $transcodingSteps = [
            ['720p_h264', 400000],  // 400ms
            ['480p_h264', 300000],  // 300ms  
            ['1080p_h265', 800000], // 800ms
        ];

        foreach ($transcodingSteps as [$format, $microseconds]) {
            echo "    Transcoding to $format...\n";
            usleep($microseconds); // VERY SLOW PROCESSING
            
            $this->logger->event(new PerformanceMetricsContext(
                $microseconds / 1000000,
                80 * 1024 * 1024, // High memory usage during transcoding
                120 * 1024 * 1024,
                0,
                0.0,
                0,
                0,
                [
                    'ffmpeg_transcode' => ['calls' => 1, 'time' => $microseconds / 1000000],
                    'gpu_acceleration' => ['calls' => 1, 'time' => ($microseconds / 1000000) * 0.3],
                ]
            ));
        }

        $transcodingEndTime = microtime(true);
        $transcodingTime = $transcodingEndTime - ($startTime + 0.8); // Subtract upload time

        $this->logger->close(new FileProcessingContext(
            'video_transcoding',
            'video_12345.mp4',
            'video/mp4',
            157286400,
            $transcodingTime,
            true,
            '/tmp/processed/video_12345_multi.mp4'
        ), $transcodingId);

        // Fast database operations to save metadata
        $dbId = $this->logger->open(new DatabaseConnectionContext('mysql', 'media-db.example.com', 'media_storage', 0.012, true));
        
        usleep(15000); // 15ms for metadata insertion
        $this->logger->event(new ComplexQueryContext(
            'INSERT',
            'video_files',
            [],
            8, // Video metadata fields
            0.015,
            1,
            false
        ));

        $this->logger->close(new DatabaseConnectionContext('mysql', 'media-db.example.com', 'media_storage', 0.012, true), $dbId);

        $endTime = microtime(true);

        // Final performance summary
        $this->logger->event(new PerformanceMetricsContext(
            $endTime - $startTime,
            120 * 1024 * 1024, // Peak memory during processing
            150 * 1024 * 1024,
            1,
            0.015,
            0,
            0,
            [
                'ffmpeg_transcode' => ['calls' => 3, 'time' => $transcodingTime],
                'file_io' => ['calls' => 10, 'time' => 0.2],
                'mysql_query' => ['calls' => 1, 'time' => 0.015],
            ]
        ));

        $this->logger->close(new HttpResponseContext(
            200,
            ['Content-Type' => 'application/json'],
            512,
            'application/json',
            $endTime - $startTime,
            false
        ), $httpRequestId);

        $this->devLogger->log($this->logger);

        echo "Heavy file processing completed!\n";
        echo "Total time: " . number_format(($endTime - $startTime) * 1000, 0) . "ms\n";
        echo "Transcoding time: " . number_format($transcodingTime * 1000, 0) . "ms - MAJOR BOTTLENECK!\n";
        echo "Peak memory: 150MB during processing\n\n";
    }

    public function simulateCacheMissStorm(): void
    {
        echo "=== Cache Miss Storm Simulation ===\n";

        $startTime = microtime(true);

        $httpRequestId = $this->logger->open(new HttpRequestContext(
            'GET',
            '/api/popular-products',
            [],
            null,
            'ECommerce/3.0',
            '203.0.113.45'
        ));

        // Fast auth
        $authId = $this->logger->open(new AuthenticationContext('session', null, [], false, 0.0));
        usleep(2000); // 2ms
        $this->logger->close(new AuthenticationContext('session', 'guest', [], true, 0.002), $authId);

        $businessId = $this->logger->open(new BusinessLogicContext(
            'popular_products_aggregation',
            ['category' => 'electronics', 'limit' => 20],
            [],
            [],
            false
        ));

        // THE PROBLEM: Cache system is down/purged - everything is a miss!
        $dbId = $this->logger->open(new DatabaseConnectionContext('mysql', 'products-db.example.com', 'ecommerce', 0.025, true));

        $cacheableQueries = [
            ['product_categories', 0.045, 5],
            ['popular_products_today', 0.180, 20],
            ['product_ratings', 0.095, 20],
            ['inventory_status', 0.220, 20], // Slowest query
            ['product_images', 0.035, 100], // Many small queries
            ['pricing_tiers', 0.065, 20],
            ['shipping_costs', 0.040, 20],
            ['tax_rates', 0.055, 50],
        ];

        $totalCacheMisses = 0;
        $totalQueryTime = 0;

        foreach ($cacheableQueries as [$queryType, $baseTime, $count]) {
            for ($i = 0; $i < $count; $i++) {
                // Every cache check is a MISS!
                $cacheKey = "{$queryType}_item_" . ($i + 1);
                $this->logger->event(new CacheOperationContext(
                    'get',
                    $cacheKey,
                    false, // CACHE MISS!
                    0.001, // Fast cache check
                    3600,
                    0
                ));
                $totalCacheMisses++;

                // Now we have to hit the database (SLOW!)
                $queryTime = $baseTime + (rand(-10, 20) / 1000); // Add some variance
                usleep(intval($queryTime * 1000000)); // Convert to microseconds
                
                $this->logger->event(new ComplexQueryContext(
                    'SELECT',
                    $queryType,
                    ['id' => $i + 1],
                    3,
                    $queryTime,
                    1,
                    false // No cache to store in right now
                ));
                $totalQueryTime += $queryTime;
            }
        }

        $this->logger->close(new DatabaseConnectionContext('mysql', 'products-db.example.com', 'ecommerce', 0.025, true), $dbId);

        $endTime = microtime(true);

        $this->logger->close(new BusinessLogicContext(
            'popular_products_aggregation',
            ['category' => 'electronics', 'limit' => 20],
            ['products' => 20, 'cache_misses' => $totalCacheMisses, 'fallback_to_db' => true],
            [],
            true
        ), $businessId);

        // Performance showing cache miss impact
        $this->logger->event(new PerformanceMetricsContext(
            $endTime - $startTime,
            memory_get_usage() - memory_get_usage(),
            memory_get_peak_usage(),
            255, // Total database queries (should have been 0 with cache!)
            $totalQueryTime,
            0, // NO cache hits
            $totalCacheMisses, // ALL cache misses
            [
                'mysql_query' => ['calls' => 255, 'time' => $totalQueryTime],
                'redis_get' => ['calls' => $totalCacheMisses, 'time' => $totalCacheMisses * 0.001],
            ]
        ));

        $this->logger->close(new HttpResponseContext(
            200,
            ['Content-Type' => 'application/json'],
            4096, // Large response due to no caching optimizations
            'application/json',
            $endTime - $startTime,
            false
        ), $httpRequestId);

        $this->devLogger->log($this->logger);

        echo "Cache Miss Storm completed!\n";
        echo "Total time: " . number_format(($endTime - $startTime) * 1000, 0) . "ms\n";
        echo "Database queries: 255 (should have been 0 with cache!)\n";
        echo "Cache misses: $totalCacheMisses (100% miss rate) - CACHE IS DOWN!\n\n";
    }

    public function runAllSlowScenarios(): void
    {
        echo "=== Running All Slow Performance Scenarios ===\n\n";

        $this->simulateN1QueryProblem();
        $this->simulateSlowExternalAPI();
        $this->simulateHeavyFileProcessing();
        $this->simulateCacheMissStorm();

        echo "All slow performance scenarios completed!\n";
        echo "Check /tmp for semantic-dev-*.json files with realistic performance problems.\n";
        echo "\nThese logs will show Claude exactly what performance issues to focus on!\n";
    }
}

// Run scenarios if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $scenarios = new SlowPerformanceScenarios();
    $scenarios->runAllSlowScenarios();
}