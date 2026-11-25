<?php

namespace CronRunner\Tests\Unit;

use CronRunner\AdapterFactory;
use CronRunner\Adapters\Runner\CrunzAdapter;
use CronRunner\Adapters\Runner\LaravelAdapter;
use CronRunner\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdapterFactoryTest extends TestCase
{
    private string|false $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = getenv(AdapterFactory::ENV_VARIABLE);
    }

    protected function tearDown(): void
    {
        putenv($this->originalEnvironment === false
            ? AdapterFactory::ENV_VARIABLE
            : AdapterFactory::ENV_VARIABLE . '=' . $this->originalEnvironment);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, class-string}>
     */
    public static function names(): array
    {
        return [
            'laravel' => ['laravel', LaravelAdapter::class],
            'crunz' => ['crunz', CrunzAdapter::class],
            'any letter case' => ['CrUnZ', CrunzAdapter::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('names')]
    public function testCreatesAdapterByName(string $name, string $class): void
    {
        self::assertInstanceOf($class, AdapterFactory::create($name, 'UTC'));
    }

    public function testRejectsUnknownName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown CRON_RUNNER_ADAPTER "cron", use "laravel" or "crunz".');

        AdapterFactory::create('cron', 'UTC');
    }

    public function testRejectsInvalidTimezone(): void
    {
        $this->expectException(\Exception::class);

        AdapterFactory::create('laravel', 'Mars/Base');
    }

    /**
     * @return array<string, array{string|null, class-string}>
     */
    public static function environments(): array
    {
        return [
            'not set' => [null, LaravelAdapter::class],
            'empty' => ['', LaravelAdapter::class],
            'laravel' => ['laravel', LaravelAdapter::class],
            'crunz' => ['crunz', CrunzAdapter::class],
        ];
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('environments')]
    public function testCreatesAdapterFromEnvironment(?string $value, string $class): void
    {
        putenv($value === null ? AdapterFactory::ENV_VARIABLE : AdapterFactory::ENV_VARIABLE . '=' . $value);

        self::assertInstanceOf($class, AdapterFactory::fromEnvironment('UTC'));
    }

    public function testRejectsUnknownNameFromEnvironment(): void
    {
        putenv(AdapterFactory::ENV_VARIABLE . '=cron');

        $this->expectException(\InvalidArgumentException::class);

        AdapterFactory::fromEnvironment('UTC');
    }
}
