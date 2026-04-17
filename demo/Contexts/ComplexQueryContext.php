<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger;

use Koriym\SemanticLogger\Profiler\XdebugTrace;
use Koriym\SemanticLogger\Profiler\XHProfResult;

use function usleep;

final class ComplexQueryContext extends AbstractContext
{
    public const TYPE = 'complex_query';
    public const SCHEMA_URL = 'https://example.com/schema/complex-query.json';

    public readonly XHProfResult $xhprofResult;
    public readonly XdebugTrace $xdebugTrace;

    public function __construct(
        public readonly string $queryType,
        public readonly string $table,
        public readonly array $parameters,
        public readonly int $fieldCount,
        public readonly float $executionTime,
        public readonly int $affectedRows,
        public readonly bool $hasError,
        public readonly string|null $customerId = null,
    ) {
        // 自動プロファイリング
        $this->xhprofResult = $this->createXHProfResult();
        $this->xdebugTrace = $this->createXdebugTrace();
    }

    private function createXHProfResult(): XHProfResult
    {
        $xhprof = XHProfResult::start();
        // クエリ実行をシミュレート（実際のクエリ実行時間相当）
        usleep((int) ($this->executionTime * 1000000));

        return $xhprof->stop($this->table . '/' . $this->queryType);
    }

    private function createXdebugTrace(): XdebugTrace
    {
        $trace = XdebugTrace::start();
        // クエリ実行をシミュレート
        usleep((int) ($this->executionTime * 1000000));

        return $trace->stop();
    }
}
