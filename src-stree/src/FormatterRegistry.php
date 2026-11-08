<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function is_subclass_of;
use function json_decode;

final class FormatterRegistry
{
    /** @var array<string, NodeFormatterInterface> */
    private array $map = [];

    /**
     * Formatters that installed packages declare as `extra.stree.formatters` (`{"<type>": "<class>"}`) in Composer's
     * installed.json. Each class takes no constructor arguments; one that is missing or not a formatter is skipped.
     */
    public static function fromInstalledPackages(string $installedJson): self
    {
        $registry = new self();
        $json = is_file($installedJson) ? file_get_contents($installedJson) : false;
        /** @var mixed $installed */
        $installed = is_string($json) ? json_decode($json, true) : null;
        /** @var mixed $package */
        foreach (self::arrayAt($installed, 'packages') as $package) {
            /** @var mixed $class */
            foreach (self::arrayAt($package, 'extra', 'stree', 'formatters') as $type => $class) {
                if (is_string($type) && is_string($class) && is_subclass_of($class, NodeFormatterInterface::class)) {
                    $registry->register($type, new $class());
                }
            }
        }

        return $registry;
    }

    public function register(string $type, NodeFormatterInterface $formatter): void
    {
        $this->map[$type] = $formatter;
    }

    public function get(string $type): NodeFormatterInterface|null
    {
        return $this->map[$type] ?? null;
    }

    /** @return array<array-key, mixed> */
    private static function arrayAt(mixed $value, string ...$keys): array
    {
        foreach ($keys as $key) {
            /** @var mixed $value */
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_array($value) ? $value : [];
    }
}
