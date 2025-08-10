<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use JsonSerializable;
use Override;

use function count;
use function date;
use function file_put_contents;
use function function_exists;
use function json_encode;
use function md5;
use function sys_get_temp_dir;
use function xhprof_disable;
use function xhprof_enable;

use const JSON_PRETTY_PRINT;
use const XHPROF_FLAGS_CPU;
use const XHPROF_FLAGS_MEMORY;

final class XHProfResult implements JsonSerializable
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public readonly array|null $data = null,
        public readonly string|null $filePath = null,
    ) {
    }

    public static function start(): self
    {
        if (! function_exists('xhprof_enable')) {
            return new self(); // @codeCoverageIgnore
        }

        /** @psalm-suppress UndefinedConstant, MixedArgument */
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY); // development: include built-ins for complete analysis

        return new self();
    }

    public function stop(string $uri): self
    {
        if (! function_exists('xhprof_disable')) {
            return new self(); // @codeCoverageIgnore
        }

        $xhprofData = xhprof_disable();

        // xhprof_disable() returns array|false according to PHPStan
        /** @psalm-suppress TypeDoesNotContainType */
        if ($xhprofData === false) { /** @phpstan-ignore-line identical.alwaysFalse */
            return new self(); // @codeCoverageIgnore
        }

        if (count($xhprofData) === 0) {
            return new self();
        }

        // Save data to file and return reference
        /** @var array<string, mixed> $xhprofData */
        $filePath = $this->saveToFile($xhprofData, $uri);

        return new self($xhprofData, $filePath);
    }

    /**
     * @param array<string, mixed> $data
     * @codeCoverageIgnore
     */
    private function saveToFile(array $data, string $uri): string
    {
        $filename = 'xhprof_' . date('Y-m-d_H-i-s') . '_' . md5($uri) . '.json';
        $filePath = sys_get_temp_dir() . '/' . $filename;

        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) {
            $json = '{}';
        }

        file_put_contents($filePath, $json);

        return $filePath;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        if ($this->data === null) {
            return [];
        }

        return [
            'source' => $this->filePath, // file path or inline data
        ];
    }
}
