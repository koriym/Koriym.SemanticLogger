<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Throwable;

use function file_put_contents;
use function function_exists;
use function get_class;
use function json_encode;
use function memory_get_peak_usage;
use function memory_get_usage;
use function microtime;
use function number_format;
use function uniqid;
use function usleep;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

/**
 * Complex web request simulation with nested open/close/event patterns
 *
 * This test simulates a realistic e-commerce API request with:
 * - Authentication
 * - Multiple database queries
 * - External service calls
 * - File processing
 * - Caching operations
 * - Error handling
 * - Performance monitoring
 */
class ComplexWebRequestSimulation
{
    private SemanticLogger $logger;
    private DevLogger $devLogger;

    public function __construct()
    {
        $this->logger = new SemanticLogger();
        $this->devLogger = new DevLogger('/tmp');
    }

    public function simulateECommerceOrderProcessing(): void
    {
        echo "=== Starting E-Commerce Order Processing Simulation ===\n";

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        // Debug: First logger call
        echo "Debug: About to call first logger->open()...\n";
        echo 'Debug: Logger instance: ' . get_class($this->logger) . "\n";

        // 1. HTTP Request arrives
        $httpRequestId = $this->logger->open(new HttpRequestContext(
            'POST',
            '/api/orders',
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
                'User-Agent' => 'ECommerceApp/2.1.0',
                'X-Request-ID' => 'req_' . uniqid(),
            ],
            [
                'customerId' => 12345,
                'items' => [
                    ['productId' => 'P001', 'quantity' => 2, 'price' => 29.99],
                    ['productId' => 'P045', 'quantity' => 1, 'price' => 149.99],
                ],
                'shippingAddress' => ['street' => '123 Main St', 'city' => 'Tokyo'],
                'paymentMethod' => 'credit_card_ending_1234',
            ],
            'ECommerceApp/2.1.0 (iOS 17.0)',
            '192.168.1.100',
        ));

        usleep(5000); // Initial request processing delay

        // 2. Authentication Process
        $authId = $this->logger->open(new AuthenticationContext(
            'JWT',
            null, // Will be set after successful auth
            [],
            false,
            0.0,
        ));

        // Auth validation steps
        $this->logger->event(new CacheOperationContext(
            'get',
            'jwt_blacklist_check',
            true, // Cache hit
            0.002,
            3600,
            128,
        ));

        usleep(8000); // JWT verification time

        $this->logger->close(new AuthenticationContext(
            'JWT',
            'user_12345',
            ['role' => 'customer', 'premium' => true],
            true,
            0.015,
        ), $authId);

        // 3. Business Logic - Order Validation
        $orderValidationId = $this->logger->open(new BusinessLogicContext(
            'order_validation',
            [
                'customerId' => 12345,
                'items' => ['P001', 'P045'],
                'total' => 209.97,
            ],
            [],
            [
                'customer_exists' => true,
                'items_available' => true,
                'payment_valid' => true,
                'shipping_valid' => true,
            ],
            false,
        ));

        // 4. Database Operations
        $dbConnectionId = $this->logger->open(new DatabaseConnectionContext(
            'mysql',
            'db-cluster-1.example.com',
            'ecommerce_prod',
            0.045,
            true, // Using connection pool
        ));

        // Customer validation query
        $this->logger->event(new ComplexQueryContext(
            'SELECT',
            'customers',
            ['id' => 12345, 'active' => 1],
            1,
            0.012,
            1,
            true,
            'customer_12345',
        ));

        // Inventory check query
        $this->logger->event(new ComplexQueryContext(
            'SELECT',
            'inventory i JOIN products p ON i.product_id = p.id',
            ['product_id' => ['P001', 'P045'], 'available_quantity >' => 0],
            2,
            0.028,
            2,
            false,
        ));

        // 5. External Service Call - Payment Gateway
        $paymentId = $this->logger->open(new ExternalApiContext(
            'PaymentGateway',
            'https://api.payments.example.com/v2/authorize',
            'POST',
            0, // Will be set when closed
            0.0,
            512, // Request size
            0,    // Response size TBD
        ));

        usleep(250000); // Payment gateway processing time

        // Payment authorization successful
        $this->logger->close(new ExternalApiContext(
            'PaymentGateway',
            'https://api.payments.example.com/v2/authorize',
            'POST',
            200,
            0.247,
            512,
            256,
            0,
        ), $paymentId);

        // 6. Inventory Update
        $this->logger->event(new ComplexQueryContext(
            'UPDATE',
            'inventory',
            ['product_id' => ['P001', 'P045']],
            4, // 2 products, 2 fields each
            0.035,
            2,
            false,
        ));

        // 7. Order Creation
        $orderInsertId = $this->logger->open(new ComplexQueryContext(
            'INSERT',
            'orders',
            [],
            8, // Order fields
            0.0,
            0,
            false,
        ));

        // Order items insertion
        $this->logger->event(new ComplexQueryContext(
            'INSERT',
            'order_items',
            [],
            6, // 2 items * 3 fields each
            0.018,
            2,
            false,
        ));

        $this->logger->close(new ComplexQueryContext(
            'INSERT',
            'orders',
            [],
            8,
            0.042,
            1,
            false,
        ), $orderInsertId);

        $this->logger->close(new DatabaseConnectionContext(
            'mysql',
            'db-cluster-1.example.com',
            'ecommerce_prod',
            0.045,
            true,
        ), $dbConnectionId);

        // 8. External Service - Shipping Label Generation
        $shippingId = $this->logger->open(new ExternalApiContext(
            'ShippingService',
            'https://api.shipping.example.com/v1/labels',
            'POST',
            0,
            0.0,
            1024,
            0,
        ));

        usleep(180000); // Shipping service processing

        $this->logger->close(new ExternalApiContext(
            'ShippingService',
            'https://api.shipping.example.com/v1/labels',
            'POST',
            201,
            0.178,
            1024,
            2048,
        ), $shippingId);

        // 9. File Processing - Invoice PDF Generation
        $invoiceId = $this->logger->open(new FileProcessingContext(
            'pdf_generation',
            'invoice_ORD_' . uniqid() . '.pdf',
            'application/pdf',
            0,
            0.0,
            false,
        ));

        usleep(120000); // PDF generation time

        $this->logger->close(new FileProcessingContext(
            'pdf_generation',
            'invoice_ORD_67890.pdf',
            'application/pdf',
            15360, // 15KB PDF
            0.118,
            true,
            '/tmp/invoices/invoice_ORD_67890.pdf',
        ), $invoiceId);

        // 10. Cache Operations - Store order summary
        $this->logger->event(new CacheOperationContext(
            'set',
            'order_summary_12345',
            true, // Successful set
            0.003,
            1800, // 30 minutes TTL
            512,
        ));

        // 11. Notification - Email Service
        $emailId = $this->logger->open(new ExternalApiContext(
            'EmailService',
            'https://api.email.example.com/v1/send',
            'POST',
            0,
            0.0,
            2048,
            0,
        ));

        usleep(95000); // Email service time

        $this->logger->close(new ExternalApiContext(
            'EmailService',
            'https://api.email.example.com/v1/send',
            'POST',
            202, // Accepted for delivery
            0.092,
            2048,
            128,
        ), $emailId);

        // 12. Business Logic Completion
        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $this->logger->close(new BusinessLogicContext(
            'order_validation',
            [
                'customerId' => 12345,
                'items' => ['P001', 'P045'],
                'total' => 209.97,
            ],
            [
                'orderId' => 'ORD_67890',
                'status' => 'confirmed',
                'estimatedDelivery' => '2025-08-09',
            ],
            [
                'customer_exists' => true,
                'items_available' => true,
                'payment_valid' => true,
                'shipping_valid' => true,
            ],
            true,
        ), $orderValidationId);

        // 13. Performance Metrics
        $this->logger->event(new PerformanceMetricsContext(
            $endTime - $startTime,
            $endMemory - $startMemory,
            memory_get_peak_usage(),
            5, // Total queries
            0.135, // Total query time
            2, // Cache hits
            0, // Cache misses
            [
                'mysql_query' => ['calls' => 5, 'time' => 0.135],
                'curl_exec' => ['calls' => 3, 'time' => 0.517],
                'json_encode' => ['calls' => 8, 'time' => 0.004],
                'password_verify' => ['calls' => 1, 'time' => 0.015],
                'pdf_create' => ['calls' => 1, 'time' => 0.118],
            ],
        ));

        // 14. HTTP Response
        $this->logger->close(new HttpResponseContext(
            201, // Created
            [
                'Content-Type' => 'application/json',
                'X-Response-Time' => number_format(($endTime - $startTime) * 1000, 2) . 'ms',
                'X-Request-ID' => 'req_' . uniqid(),
            ],
            256, // Response size
            'application/json',
            $endTime - $startTime,
            false,
        ), $httpRequestId);

        // Don't flush here - save for final flush

        echo "E-Commerce order processing completed!\n";
        echo 'Total execution time: ' . number_format(($endTime - $startTime) * 1000, 2) . "ms\n";
        echo 'Memory used: ' . number_format(($endMemory - $startMemory) / 1024, 2) . "KB\n";
        echo 'Peak memory: ' . number_format(memory_get_peak_usage() / 1024, 2) . "KB\n";
    }

    public function simulateErrorScenario(): void
    {
        echo "\n=== Starting Error Scenario Simulation ===\n";

        $startTime = microtime(true);

        // HTTP Request with error (new request, different ID)
        $errorHttpRequestId = $this->logger->open(new HttpRequestContext(
            'POST',
            '/api/orders',
            ['Content-Type' => 'application/json'],
            ['invalid' => 'data'],
            'BadClient/1.0',
            '192.168.1.200',
        ));

        // Authentication failure
        $authId = $this->logger->open(new AuthenticationContext(
            'JWT',
            null,
            [],
            false,
            0.0,
        ));

        $this->logger->event(new ErrorContext(
            'AuthenticationError',
            'Invalid JWT token: signature verification failed',
            401,
            '/src/Auth/JwtValidator.php',
            45,
            [
                '/src/Auth/JwtValidator.php:45',
                '/src/Middleware/AuthMiddleware.php:78',
                '/src/Controllers/OrderController.php:23',
            ],
            ['token_header' => 'Bearer invalid_token'],
        ));

        $this->logger->close(new AuthenticationContext(
            'JWT',
            null,
            [],
            false,
            0.008,
        ), $authId);

        $endTime = microtime(true);

        // Error response
        $this->logger->close(new HttpResponseContext(
            401,
            ['Content-Type' => 'application/json'],
            128,
            'application/json',
            $endTime - $startTime,
            false,
        ), $errorHttpRequestId);

        // Don't flush here either - combine with main session

        echo "Error scenario completed!\n";
        echo 'Response time: ' . number_format(($endTime - $startTime) * 1000, 2) . "ms\n";
    }

    public function run(): void
    {
        // Start Xdebug trace manually
        echo "=== Debug: Starting Xdebug trace ===\n";
        if (function_exists('xdebug_start_trace')) {
            $traceFile = '/Users/akihito/git/Koriym.SemanticLogger/demo/xdebug_trace.xt';
            xdebug_start_trace($traceFile);
            echo "Xdebug trace started: {$traceFile}.xt\n";
        }

        $this->simulateECommerceOrderProcessing();
        $this->simulateErrorScenario();

        // Stop Xdebug trace
        if (function_exists('xdebug_stop_trace')) {
            xdebug_stop_trace();
            echo "Xdebug trace stopped\n";
        }

        // Debug: Check logger state before flush
        echo "=== Debug: Logger state before flush ===\n";
        echo 'Logger class: ' . get_class($this->logger) . "\n";

        // Generate JSON from the logger and save to demo folder
        try {
            echo "Attempting to flush logger...\n";
            $logJson = $this->logger->flush();
            echo 'Flush successful! Log has entries: ' . ! empty($logJson->toArray()) . "\n";
            $jsonString = json_encode($logJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Also save via DevLogger for AI debugging
            $this->devLogger->saveToFile($logJson);
            echo "DevLogger also saved copy to /tmp\n";
        } catch (Throwable $e) {
            // If no log session, create empty log
            echo 'Flush failed: ' . $e->getMessage() . "\n";
            echo 'Exception type: ' . $e::class . "\n";
            $jsonString = json_encode([], JSON_PRETTY_PRINT);
        }

        $outputPath = __DIR__ . '/semantic-log.json';
        file_put_contents($outputPath, $jsonString);

        echo "\nAll complex web request tests completed!\n";
        echo "Generated semantic log saved to: {$outputPath}\n";
        echo "Check /tmp for semantic-dev-*.json files with complex nested data.\n";
    }
}
