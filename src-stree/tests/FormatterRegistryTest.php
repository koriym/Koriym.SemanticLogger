<?php

declare(strict_types=1);

namespace Koriym\SemanticLogger\Stree;

use Koriym\SemanticLogger\Stree\Fake\AbstractFakeFormatter;
use Koriym\SemanticLogger\Stree\Fake\FakeFormatter;
use Koriym\SemanticLogger\Stree\Fake\FakeFormatterWithArgument;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

final class FormatterRegistryTest extends TestCase
{
    private string $installedJson = '';

    protected function tearDown(): void
    {
        if ($this->installedJson !== '' && file_exists($this->installedJson)) {
            unlink($this->installedJson);
        }
    }

    public function testGetReturnsNullForUnregisteredType(): void
    {
        $registry = new FormatterRegistry();

        $this->assertNull($registry->get('unknown_type'));
    }

    public function testRegisterAndGet(): void
    {
        $registry = new FormatterRegistry();
        $formatter = new FakeFormatter();

        $registry->register('fake_open', $formatter);

        $this->assertSame($formatter, $registry->get('fake_open'));
    }

    public function testRegisterOverwritesExistingEntry(): void
    {
        $registry = new FormatterRegistry();
        $first = new FakeFormatter();
        $second = new FakeFormatter();

        $registry->register('fake_open', $first);
        $registry->register('fake_open', $second);

        $this->assertSame($second, $registry->get('fake_open'));
    }

    public function testRegistryIsolatesTypes(): void
    {
        $registry = new FormatterRegistry();
        $formatter = new FakeFormatter();

        $registry->register('type_a', $formatter);

        $this->assertSame($formatter, $registry->get('type_a'));
        $this->assertNull($registry->get('type_b'));
    }

    public function testFromInstalledPackagesRegistersDeclaredFormattersAndSkipsBrokenOnes(): void
    {
        $this->installedJson = (string) tempnam(sys_get_temp_dir(), 'stree_installed_');
        file_put_contents($this->installedJson, json_encode([
            'packages' => [
                ['name' => 'fake/formatter', 'extra' => ['stree' => ['formatters' => ['fake_open' => FakeFormatter::class]]]],
                [
                    'name' => 'fake/broken',
                    'extra' => [
                        'stree' => [
                            'formatters' => [
                                'missing_class' => 'Koriym\SemanticLogger\Stree\Fake\NoSuchFormatter',
                                'not_a_formatter' => self::class,
                                'abstract_formatter' => AbstractFakeFormatter::class,
                                'needs_argument' => FakeFormatterWithArgument::class,
                            ],
                        ],
                    ],
                ],
                ['name' => 'fake/plain'],
            ],
        ], JSON_THROW_ON_ERROR));

        $registry = FormatterRegistry::fromInstalledPackages($this->installedJson);

        $this->assertInstanceOf(FakeFormatter::class, $registry->get('fake_open'));
        $this->assertNull($registry->get('missing_class'));
        $this->assertNull($registry->get('not_a_formatter'));
        $this->assertNull($registry->get('abstract_formatter'));
        $this->assertNull($registry->get('needs_argument'));
    }

    public function testFromInstalledPackagesWithoutTheFileIsEmpty(): void
    {
        $registry = FormatterRegistry::fromInstalledPackages(sys_get_temp_dir() . '/stree-no-such-installed.json');

        $this->assertNull($registry->get('fake_open'));
    }
}
