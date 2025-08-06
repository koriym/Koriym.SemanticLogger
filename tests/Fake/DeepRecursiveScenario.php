<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/ComplexWebRequestContexts.php';

/**
 * Deep recursive scenario with nested open/close patterns
 * 
 * Simulates complex hierarchical operations like:
 * - Recursive directory processing
 * - Nested transaction processing
 * - Multi-level service calls
 * - Hierarchical data processing
 */
class DeepRecursiveScenario
{
    private SemanticLogger $logger;
    private DevLogger $devLogger;
    private int $currentDepth = 0;

    public function __construct()
    {
        $this->logger = new SemanticLogger();
        $this->devLogger = new DevLogger('/tmp');
    }

    public function simulateRecursiveFileProcessing(): void
    {
        echo "=== Deep Recursive File Processing Scenario ===\n";

        $startTime = microtime(true);

        // Top-level HTTP request
        $httpRequestId = $this->logger->open(new HttpRequestContext(
            'POST',
            '/api/process-directory-tree',
            ['Content-Type' => 'application/json'],
            ['rootPath' => '/data/documents', 'recursive' => true],
            'FileProcessor/2.0',
            '10.0.2.45'
        ));

        // Authentication
        $authId = $this->logger->open(new AuthenticationContext('Bearer', null, [], false, 0.0));
        usleep(3000); // 3ms
        $this->logger->close(new AuthenticationContext('Bearer', 'admin_user', ['role' => 'admin'], true, 0.003), $authId);

        // Main business logic
        $businessId = $this->logger->open(new BusinessLogicContext(
            'recursive_directory_processing',
            ['rootPath' => '/data/documents', 'maxDepth' => 5],
            [],
            [],
            false
        ));

        // Start recursive processing
        $processedCount = $this->processDirectory('/data/documents', 0, 5);

        $endTime = microtime(true);

        // Complete business logic
        $this->logger->close(new BusinessLogicContext(
            'recursive_directory_processing',
            ['rootPath' => '/data/documents', 'maxDepth' => 5],
            ['totalFilesProcessed' => $processedCount, 'maxDepthReached' => 5],
            [],
            true
        ), $businessId);

        // Performance summary
        $this->logger->event(new PerformanceMetricsContext(
            $endTime - $startTime,
            memory_get_usage(),
            memory_get_peak_usage(),
            0, // No direct DB queries in this scenario
            0.0,
            0,
            0,
            [
                'file_operations' => ['calls' => $processedCount, 'time' => $endTime - $startTime],
                'recursive_calls' => ['calls' => $processedCount, 'time' => ($endTime - $startTime) * 0.8],
            ]
        ));

        // HTTP response
        $this->logger->close(new HttpResponseContext(
            200,
            ['Content-Type' => 'application/json'],
            512,
            'application/json',
            $endTime - $startTime,
            false
        ), $httpRequestId);

        $this->devLogger->log($this->logger);

        echo "Recursive file processing completed!\n";
        echo "Total files processed: $processedCount\n";
        echo "Maximum nesting depth reached: 5 levels\n";
        echo "Total time: " . number_format(($endTime - $startTime) * 1000, 0) . "ms\n\n";
    }

    private function processDirectory(string $path, int $depth, int $maxDepth): int
    {
        if ($depth >= $maxDepth) {
            return 0;
        }

        $this->currentDepth = max($this->currentDepth, $depth);
        
        // Open directory processing operation
        $dirId = $this->logger->open(new FileProcessingContext(
            'directory_scan',
            basename($path),
            'directory',
            0, // Directory size unknown initially
            0.0,
            false
        ));

        echo str_repeat('  ', $depth) . "Processing directory: $path (depth: $depth)\n";

        // Simulate directory scanning time
        usleep(rand(10000, 30000)); // 10-30ms per directory

        // Simulate finding files and subdirectories
        $files = $this->generateMockDirectoryContents($path, $depth);
        $totalProcessed = 0;

        foreach ($files as $file) {
            if ($file['type'] === 'directory') {
                // Recursive call for subdirectory
                $subDirPath = $path . '/' . $file['name'];
                $subProcessed = $this->processDirectory($subDirPath, $depth + 1, $maxDepth);
                $totalProcessed += $subProcessed;
            } else {
                // Process individual file
                $fileProcessed = $this->processFile($path . '/' . $file['name'], $file, $depth);
                $totalProcessed += $fileProcessed;
            }
        }

        $endTime = microtime(true);

        // Close directory processing
        $this->logger->close(new FileProcessingContext(
            'directory_scan',
            basename($path),
            'directory',
            array_sum(array_column($files, 'size')), // Total directory size
            0.03 + ($depth * 0.01), // Processing time increases with depth
            true,
            $path . '_processed'
        ), $dirId);

        return $totalProcessed;
    }

    private function processFile(string $filePath, array $fileInfo, int $depth): int
    {
        // Open file processing operation
        $fileId = $this->logger->open(new FileProcessingContext(
            'file_processing',
            $fileInfo['name'],
            $fileInfo['mimeType'],
            $fileInfo['size'],
            0.0,
            false
        ));

        echo str_repeat('  ', $depth + 1) . "Processing file: {$fileInfo['name']} ({$fileInfo['mimeType']})\n";

        // Different processing time based on file type and size
        $processingTime = $this->calculateProcessingTime($fileInfo);
        usleep(intval($processingTime * 1000000));

        // Simulate file-type specific operations
        if ($fileInfo['mimeType'] === 'image/jpeg' || $fileInfo['mimeType'] === 'image/png') {
            // Image processing - nested operation
            $imageId = $this->logger->open(new FileProcessingContext(
                'image_optimization',
                $fileInfo['name'],
                $fileInfo['mimeType'],
                $fileInfo['size'],
                0.0,
                false
            ));

            usleep(rand(50000, 150000)); // 50-150ms for image processing
            
            $this->logger->event(new PerformanceMetricsContext(
                0.1,
                1024 * 1024, // 1MB memory for image processing
                2 * 1024 * 1024, // 2MB peak
                0,
                0.0,
                0,
                0,
                [
                    'image_resize' => ['calls' => 1, 'time' => 0.05],
                    'image_compress' => ['calls' => 1, 'time' => 0.05],
                ]
            ));

            $this->logger->close(new FileProcessingContext(
                'image_optimization',
                $fileInfo['name'],
                $fileInfo['mimeType'],
                $fileInfo['size'],
                0.1,
                true,
                $filePath . '_optimized'
            ), $imageId);

        } elseif ($fileInfo['mimeType'] === 'application/pdf') {
            // PDF processing - nested operation
            $pdfId = $this->logger->open(new FileProcessingContext(
                'pdf_text_extraction',
                $fileInfo['name'],
                $fileInfo['mimeType'],
                $fileInfo['size'],
                0.0,
                false
            ));

            usleep(rand(80000, 200000)); // 80-200ms for PDF processing

            $this->logger->event(new PerformanceMetricsContext(
                0.15,
                2 * 1024 * 1024, // 2MB memory for PDF processing
                4 * 1024 * 1024, // 4MB peak
                0,
                0.0,
                0,
                0,
                [
                    'pdf_parse' => ['calls' => 1, 'time' => 0.1],
                    'text_extract' => ['calls' => 1, 'time' => 0.05],
                ]
            ));

            $this->logger->close(new FileProcessingContext(
                'pdf_text_extraction',
                $fileInfo['name'],
                $fileInfo['mimeType'],
                $fileInfo['size'],
                0.15,
                true,
                $filePath . '.txt'
            ), $pdfId);
        }

        // Close main file processing
        $this->logger->close(new FileProcessingContext(
            'file_processing',
            $fileInfo['name'],
            $fileInfo['mimeType'],
            $fileInfo['size'],
            $processingTime,
            true,
            $filePath . '_processed'
        ), $fileId);

        return 1; // One file processed
    }

    private function generateMockDirectoryContents(string $path, int $depth): array
    {
        $contents = [];
        $baseNames = ['documents', 'images', 'reports', 'archive', 'temp', 'backup'];
        $fileTypes = [
            ['name' => 'report.pdf', 'mimeType' => 'application/pdf', 'size' => 2048576],
            ['name' => 'image.jpg', 'mimeType' => 'image/jpeg', 'size' => 1048576],
            ['name' => 'data.xlsx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size' => 512000],
            ['name' => 'photo.png', 'mimeType' => 'image/png', 'size' => 3145728],
            ['name' => 'document.docx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 256000],
        ];

        // Add subdirectories (fewer at deeper levels)
        $subdirCount = max(1, 4 - $depth);
        for ($i = 0; $i < $subdirCount; $i++) {
            $contents[] = [
                'type' => 'directory',
                'name' => $baseNames[$i] . '_' . $depth . '_' . $i,
                'size' => 0,
            ];
        }

        // Add files (consistent number per directory)
        $fileCount = rand(2, 4);
        for ($i = 0; $i < $fileCount; $i++) {
            $fileTemplate = $fileTypes[$i % count($fileTypes)];
            $contents[] = [
                'type' => 'file',
                'name' => pathinfo($fileTemplate['name'], PATHINFO_FILENAME) . '_' . $depth . '_' . $i . '.' . pathinfo($fileTemplate['name'], PATHINFO_EXTENSION),
                'mimeType' => $fileTemplate['mimeType'],
                'size' => $fileTemplate['size'] + rand(-100000, 100000), // Add some variance
            ];
        }

        return $contents;
    }

    private function calculateProcessingTime(array $fileInfo): float
    {
        $baseTime = 0.01; // 10ms base time
        $sizeMultiplier = $fileInfo['size'] / (1024 * 1024); // Time per MB
        
        return $baseTime + ($sizeMultiplier * 0.05); // 50ms per MB
    }

    public function simulateNestedTransactionProcessing(): void
    {
        echo "=== Nested Transaction Processing Scenario ===\n";

        $startTime = microtime(true);

        // Main HTTP request
        $httpRequestId = $this->logger->open(new HttpRequestContext(
            'POST',
            '/api/complex-order-processing',
            ['Content-Type' => 'application/json'],
            ['orderId' => 'ORD-12345', 'items' => 5, 'totalAmount' => 999.99],
            'OrderProcessor/3.0',
            '192.168.50.100'
        ));

        // Start main transaction
        $this->processNestedTransaction('main_order_transaction', 0, 3);

        $endTime = microtime(true);

        // Close HTTP request
        $this->logger->close(new HttpResponseContext(
            200,
            ['Content-Type' => 'application/json'],
            1024,
            'application/json',
            $endTime - $startTime,
            false
        ), $httpRequestId);

        $this->devLogger->log($this->logger);

        echo "Nested transaction processing completed!\n";
        echo "Total time: " . number_format(($endTime - $startTime) * 1000, 0) . "ms\n\n";
    }

    private function processNestedTransaction(string $transactionName, int $level, int $maxLevel): void
    {
        if ($level >= $maxLevel) {
            return;
        }

        $transactionId = $this->logger->open(new BusinessLogicContext(
            $transactionName,
            ['level' => $level, 'transactionId' => uniqid()],
            [],
            [],
            false
        ));

        echo str_repeat('  ', $level) . "Starting transaction: $transactionName (level: $level)\n";

        // Simulate transaction work
        usleep(rand(20000, 80000)); // 20-80ms per transaction level

        // Database operations for this transaction level
        $dbId = $this->logger->open(new DatabaseConnectionContext(
            'mysql',
            'transactions-db.example.com',
            'orders_db',
            0.015,
            true
        ));

        // Multiple operations per transaction level
        for ($i = 0; $i < 2; $i++) {
            usleep(rand(10000, 30000)); // 10-30ms per query
            $this->logger->event(new ComplexQueryContext(
                'UPDATE',
                'order_items',
                ['order_id' => 'ORD-12345', 'item_id' => $i],
                3,
                0.025,
                1,
                false
            ));
        }

        $this->logger->close(new DatabaseConnectionContext(
            'mysql',
            'transactions-db.example.com',
            'orders_db',
            0.015,
            true
        ), $dbId);

        // Recursive call for nested transactions
        if ($level < $maxLevel - 1) {
            $this->processNestedTransaction("sub_transaction_level_" . ($level + 1), $level + 1, $maxLevel);
            $this->processNestedTransaction("parallel_transaction_level_" . ($level + 1), $level + 1, $maxLevel);
        }

        // Complete transaction
        $this->logger->close(new BusinessLogicContext(
            $transactionName,
            ['level' => $level, 'transactionId' => uniqid()],
            ['status' => 'committed', 'operations' => 2],
            [],
            true
        ), $transactionId);
    }

    public function runAllDeepScenarios(): void
    {
        echo "=== Running All Deep Recursive Scenarios ===\n\n";

        $this->simulateRecursiveFileProcessing();
        $this->simulateNestedTransactionProcessing();

        echo "All deep recursive scenarios completed!\n";
        echo "Check /tmp for semantic-dev-*.json files with deep nesting patterns.\n";
        echo "These logs demonstrate complex hierarchical workflows!\n";
    }
}

// Run scenarios if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $scenarios = new DeepRecursiveScenario();
    $scenarios->runAllDeepScenarios();
}