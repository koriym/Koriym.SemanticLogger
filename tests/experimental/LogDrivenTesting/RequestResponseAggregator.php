<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting;

use Koriym\SemanticLogger\EventEntry;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\OpenCloseEntry;

use function str_ends_with;
use function strlen;
use function substr;

/**
 * Aggregates LogJson tree structure into flat request/response pairs
 */
final class RequestResponseAggregator
{
    /**
     * Extract request/response pairs from LogJson
     *
     * @return RequestResponsePair[]
     */
    public function aggregate(LogJson $logJson): array
    {
        $pairs = [];

        // Extract all open operations from nested structure
        $openOperations = [];
        $this->extractOpenOperations($logJson->open, $openOperations);

        // Extract all close operations from nested structure
        $closeOperations = [];
        $this->extractCloseOperations($logJson->close, $closeOperations);

        // Match open and close operations by base operation ID
        // For type-based IDs like "validation_1" and "validation_complete_1",
        // we need to find logical pairs
        foreach ($openOperations as $open) {
            foreach ($closeOperations as $close) {
                if ($this->isMatchingPair($open, $close)) {
                    $pairs[] = new RequestResponsePair($open, $close);
                    break;
                }
            }
        }

        return $pairs;
    }

    /**
     * Recursively extract all open operations from nested structure
     *
     * @param array<int, OpenCloseEntry> $operations
     */
    private function extractOpenOperations(OpenCloseEntry $open, array &$operations): void
    {
        $operations[] = $open;

        if ($open->open !== null) {
            $this->extractOpenOperations($open->open, $operations);
        }
    }

    /**
     * Recursively extract all close operations from nested structure
     *
     * @param array<int, EventEntry> $operations
     */
    private function extractCloseOperations(EventEntry|null $close, array &$operations): void
    {
        if ($close === null) {
            return;
        }

        $operations[] = $close;

        if ($close->close !== null) {
            $this->extractCloseOperations($close->close, $operations);
        }
    }

    /**
     * Check if open and close operations form a logical pair
     */
    private function isMatchingPair(OpenCloseEntry $open, EventEntry $close): bool
    {
        // For semantic logging, we need to determine if operations are related
        // This is a simple heuristic based on type similarity

        // Extract base type from request type (e.g., "validation" from "validation")
        $openBaseType = $this->extractBaseType($open->type);
        $closeBaseType = $this->extractBaseType($close->type);

        return $openBaseType === $closeBaseType;
    }

    /**
     * Extract base type from operation type
     */
    private function extractBaseType(string $type): string
    {
        // Remove common suffixes like "_start", "_complete", "_result"
        $suffixes = ['_start', '_complete', '_result', '_success', '_failure'];

        foreach ($suffixes as $suffix) {
            if (str_ends_with($type, $suffix)) {
                return substr($type, 0, -strlen($suffix));
            }
        }

        return $type;
    }
}
