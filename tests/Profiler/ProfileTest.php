<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Profiler;

use PHPUnit\Framework\TestCase;

class ProfileTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $profile = new Profile();

        $this->assertNull($profile->php);
        $this->assertSame([], $profile->operations);
    }

    public function testConstructorWithPhpProfile(): void
    {
        $php = new PhpProfile([['function' => 'test_func']]);
        $profile = new Profile(php: $php);

        $this->assertSame($php, $profile->php);
    }

    public function testConstructorWithOperations(): void
    {
        $op = new OperationProfile(wallTime: 0.0005);
        $profile = new Profile(operations: ['op_1' => $op]);

        $this->assertSame(['op_1' => $op], $profile->operations);
    }

    public function testJsonSerializeWithDefaults(): void
    {
        $profile = new Profile();
        $serialized = $profile->jsonSerialize();

        $this->assertArrayHasKey('php', $serialized);
        $this->assertSame(['backtrace' => []], $serialized['php']);
        // operations key is omitted when empty.
        $this->assertArrayNotHasKey('operations', $serialized);
    }

    public function testJsonSerializeWithPhp(): void
    {
        $backtrace = [['function' => 'func1', 'file' => '/path/file1.php', 'line' => 10]];
        $php = new PhpProfile($backtrace);

        $profile = new Profile(php: $php);
        $serialized = $profile->jsonSerialize();

        $this->assertIsArray($serialized['php']);
        $this->assertSame($backtrace, $serialized['php']['backtrace']);
        $this->assertArrayHasKey('total_wall_time', $serialized['php']);
    }

    public function testJsonSerializeWithOperations(): void
    {
        $op = new OperationProfile(wallTime: 0.001);
        $profile = new Profile(operations: ['op_1' => $op]);

        $serialized = $profile->jsonSerialize();

        $this->assertArrayHasKey('operations', $serialized);
        $operations = $serialized['operations'];
        $this->assertIsArray($operations);
        $this->assertArrayHasKey('op_1', $operations);
        $entry = $operations['op_1'];
        $this->assertIsArray($entry);
        $this->assertSame(0.001, $entry['wallTime']);
        $this->assertSame([], $entry['xdebug']);
        $this->assertSame([], $entry['xhprof']);
    }
}
