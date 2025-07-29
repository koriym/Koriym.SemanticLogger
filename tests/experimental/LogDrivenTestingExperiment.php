<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental;

use Koriym\SemanticLogger\Experimental\LogDrivenTesting\AdapterRegistry;
use Koriym\SemanticLogger\Experimental\LogDrivenTesting\Adapters\ProcessStartAdapter;
use Koriym\SemanticLogger\Experimental\LogDrivenTesting\Adapters\UserRegistrationAdapter;
use Koriym\SemanticLogger\Experimental\LogDrivenTesting\Adapters\ValidationAdapter;
use Koriym\SemanticLogger\Experimental\LogDrivenTesting\LogBasedTestRunner;
use Koriym\SemanticLogger\Experimental\LogDrivenTesting\RequestResponseAggregator;
use Koriym\SemanticLogger\SemanticLogger;
use PHPUnit\Framework\TestCase;

use function count;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;

final class LogDrivenTestingExperiment extends TestCase
{
    private SemanticLogger $logger;
    private LogBasedTestRunner $testRunner;

    protected function setUp(): void
    {
        $this->logger = new SemanticLogger();

        // Setup test runner with adapters
        $aggregator = new RequestResponseAggregator();
        $registry = new AdapterRegistry();

        $registry->register(new UserRegistrationAdapter());
        $registry->register(new ValidationAdapter());
        $registry->register(new ProcessStartAdapter());

        $this->testRunner = new LogBasedTestRunner($aggregator, $registry);
    }

    public function testBasicLogDrivenTesting(): void
    {
        // Step 1: Execute actual operations and log them
        $processId = $this->logger->open(new TestProcessContext('starting process', 1));
        $validationId = $this->logger->open(new TestValidationContext(['email' => 'required', 'password' => 'required'], ['email' => 'test@example.com', 'password' => 'secret']));

        $this->logger->close(new TestValidationResultContext(true, ['email' => 'valid', 'password' => 'valid']), $validationId);
        $this->logger->close(new TestProcessCompleteContext(1, 'started', 'Process started: starting process'), $processId);

        $logJson = $this->logger->flush();

        // Step 2: Extract request/response pairs
        $aggregator = new RequestResponseAggregator();
        $pairs = $aggregator->aggregate($logJson);

        // Debug output
        echo "\n=== LogJson Debug ===\n";
        echo 'Open: ' . json_encode($logJson->open->toArray(), JSON_PRETTY_PRINT) . "\n";
        echo 'Close: ' . json_encode($logJson->close->toArray(), JSON_PRETTY_PRINT) . "\n";
        echo 'Pairs found: ' . count($pairs) . "\n";

        $this->assertCount(2, $pairs);

        // Step 3: Run log-driven tests
        $results = $this->testRunner->runTests($logJson);

        $this->assertCount(2, $results);

        // Verify test results
        foreach ($results as $result) {
            if (! $result->passed) {
                echo "\n=== Test Failure Debug ===\n";
                echo "Operation: {$result->operationType}\n";
                echo 'Expected: ' . json_encode($result->expected, JSON_PRETTY_PRINT) . "\n";
                echo 'Actual: ' . json_encode($result->actual, JSON_PRETTY_PRINT) . "\n";
            }

            $this->assertTrue($result->passed, "Test failed for {$result->operationType}: {$result->errorMessage}");
        }

        // Display results for debugging
        echo "\n=== Log Driven Testing Results ===\n";
        foreach ($results as $result) {
            echo sprintf(
                "Operation: %s [%s] - %s\n",
                $result->operationType,
                $result->operationId,
                $result->passed ? '✅ PASSED' : '❌ FAILED',
            );
            if (! $result->passed) {
                echo '  Expected: ' . json_encode($result->expected) . "\n";
                echo '  Actual: ' . json_encode($result->actual) . "\n";
                if ($result->errorMessage) {
                    echo '  Error: ' . $result->errorMessage . "\n";
                }
            }
        }
    }

    public function testRequestResponseAggregation(): void
    {
        // Create a simple log
        $openId = $this->logger->open(new TestProcessContext('test process', 42));
        $this->logger->close(new TestProcessCompleteContext(42, 'started', 'Process started: test process'), $openId);

        $logJson = $this->logger->flush();

        // Test aggregation
        $aggregator = new RequestResponseAggregator();
        $pairs = $aggregator->aggregate($logJson);

        $this->assertCount(1, $pairs);

        $pair = $pairs[0];
        $this->assertSame('process_start_1', $pair->request->id);
        $this->assertSame('process_start', $pair->request->type);
        $this->assertSame('test process', $pair->request->context['message']);
        $this->assertSame(42, $pair->request->context['id']);

        $this->assertSame('process_complete_1', $pair->response->id);
        $this->assertSame('process_complete', $pair->response->type);
        $this->assertSame(42, $pair->response->context['process_id']);
    }
}
