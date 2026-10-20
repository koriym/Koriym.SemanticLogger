<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Experimental\LogDrivenTesting;

use Koriym\SemanticLogger\EventEntry;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\OpenCloseEntry;

use function array_filter;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * Aggregates the recorded opens and closes into flat request/response pairs
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
        $opens = array_filter($logJson->records, static fn ($record): bool => $record instanceof OpenCloseEntry);

        $pairs = [];
        foreach ($opens as $open) {
            foreach ($logJson->closes as $close) {
                if ($this->isMatchingPair($open, $close)) {
                    $pairs[] = new RequestResponsePair($open, $close);
                    break;
                }
            }
        }

        return $pairs;
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
