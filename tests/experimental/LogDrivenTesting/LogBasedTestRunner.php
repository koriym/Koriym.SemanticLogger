<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting;

use Koriym\SemanticLogger\LogJson;
use Throwable;

/**
 * Test runner that executes actual requests and compares with logged responses
 */
final class LogBasedTestRunner
{
    public function __construct(
        private RequestResponseAggregator $aggregator,
        private AdapterRegistry $registry,
    ) {
    }

    /**
     * Run all tests extracted from the LogJson
     *
     * @return TestResult[]
     */
    public function runTests(LogJson $logJson): array
    {
        $pairs = $this->aggregator->aggregate($logJson);
        $results = [];

        foreach ($pairs as $pair) {
            $results[] = $this->runSingleTest($pair);
        }

        return $results;
    }

    /**
     * Run a single test for a request/response pair
     */
    public function runSingleTest(RequestResponsePair $pair): TestResult
    {
        try {
            $adapter = $this->registry->findAdapter($pair->request->type);
            $actualResult = $adapter->execute($pair->request->context);

            $expected = $pair->response->context;

            $passed = $this->compareResults($expected, $actualResult);

            return new TestResult(
                $pair->request->id,
                $pair->request->type,
                $passed,
                $expected,
                $actualResult,
                null,
            );
        } catch (Throwable $e) {
            return new TestResult(
                $pair->request->id,
                $pair->request->type,
                false,
                $pair->response->context,
                null,
                $e->getMessage(),
            );
        }
    }

    /**
     * Compare expected and actual results
     */
    private function compareResults(mixed $expected, mixed $actual): bool
    {
        return $expected === $actual;
    }
}
