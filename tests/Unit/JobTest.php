<?php

namespace CronRunner\Tests\Unit;

use CronRunner\Interfaces\Mutex;
use CronRunner\Job;
use CronRunner\Mutex\FileMutex;
use CronRunner\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class JobTest extends TestCase
{
    public function testDefaultsToEveryMinuteWithoutMutex(): void
    {
        $job = new Job('job', static fn () => null);

        self::assertSame('job', $job->name);
        self::assertSame('* * * * *', $job->cron);
        self::assertNull($job->mutex);
    }

    /**
     * @return array<string, array{\Closure}>
     */
    public static function anonymousFunctions(): array
    {
        $captured = ['path' => '/tmp/file', 'count' => 3];

        return [
            'closure' => [function (): void {
            }],
            'static closure' => [static function (): void {
            }],
            'arrow function' => [static fn () => 1],
            'closure capturing serializable values' => [static function () use ($captured): array {
                return $captured;
            }],
        ];
    }

    #[DataProvider('anonymousFunctions')]
    public function testAcceptsAnonymousFunction(\Closure $callback): void
    {
        self::assertSame($callback, (new Job('job', $callback))->callback);
    }

    /**
     * Crunz can't restore these in its job process; Laravel could run them, so they'd break only after switching.
     *
     * @return array<string, array{\Closure}>
     */
    public static function convertedCallables(): array
    {
        return [
            'function' => [phpversion(...)],
            'function by name' => [\Closure::fromCallable('phpversion')],
            'static method' => [self::staticCallback(...)],
            'object method' => [(new \ArrayObject())->count(...)],
            'invokable object' => [\Closure::fromCallable(new class {
                public function __invoke(): void
                {
                }
            })],
        ];
    }

    #[DataProvider('convertedCallables')]
    public function testRejectsFunctionsAndMethodsConvertedToClosure(\Closure $callback): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Job "job" callback must be an anonymous function');

        new Job('job', $callback);
    }

    public static function staticCallback(): void
    {
    }

    public function testRejectsClosureCapturingValueWhichCanNotBeSerialized(): void
    {
        $connection = new class {
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Job "job" callback can\'t be serialized');

        new Job('job', static fn () => $connection);
    }

    /**
     * Object of a class, which Composer can't find, like one declared in Configuration.php.
     */
    private static function notAutoloadable(): \Fixtures\NotAutoloadable
    {
        require_once self::projectRoot() . '/tests/Fixtures/not-autoloadable.php';

        return new \Fixtures\NotAutoloadable();
    }

    /**
     * @return array<string, array{\Closure}>
     */
    public static function closuresUsingNotAutoloadableClass(): array
    {
        $object = self::notAutoloadable();
        $nested = (object) ['inner' => [$object]];
        $innerClosure = static fn () => $object;

        return [
            'defined in its method' => [$object->job()],
            'defined in its static method' => [\Fixtures\NotAutoloadable::staticJob()],
            'capturing its object' => [static fn () => $object],
            'capturing it in array and object' => [static fn () => $nested],
            'capturing closure, which captures it' => [static fn () => $innerClosure],
        ];
    }

    #[DataProvider('closuresUsingNotAutoloadableClass')]
    public function testRejectsClosureUsingClassWhichCanNotBeAutoloaded(\Closure $callback): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Job "job" callback uses class Fixtures\NotAutoloadable, which Composer can\'t autoload',
        );

        new Job('job', $callback);
    }

    public function testAcceptsClosureUsingAutoloadableAndBuiltInClasses(): void
    {
        $mutex = new FileMutex($this->temporaryDirectory());
        $date = new \DateTimeImmutable();
        $cycle = new \stdClass();
        $cycle->self = $cycle;

        $callback = static fn () => [$mutex, $date, $cycle];

        self::assertSame($callback, (new Job('job', $callback))->callback);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyNames(): array
    {
        return ['empty' => [''], 'whitespace' => ['  ']];
    }

    #[DataProvider('emptyNames')]
    public function testRejectsEmptyName(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Job name can't be empty");

        new Job($name, static fn () => null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validCrons(): array
    {
        return [
            'every minute' => ['* * * * *'],
            'steps, ranges and lists' => ['*/5 0-6 * * 1,3,5'],
            'yearly' => ['0 0 1 1 *'],
            'alias' => ['@daily'],
        ];
    }

    #[DataProvider('validCrons')]
    public function testAcceptsValidCron(string $cron): void
    {
        self::assertSame($cron, (new Job('job', static fn () => null, $cron))->cron);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCrons(): array
    {
        return [
            'value out of range' => ['60 * * * *'],
            'too few fields' => ['* * * *'],
            'seconds field' => ['* * * * * *'],
            'text' => ['hello'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidCrons')]
    public function testRejectsInvalidCronWhenCreated(string $cron): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Job \"job\" has invalid cron expression \"{$cron}\"");

        new Job('job', static fn () => null, $cron);
    }

    public function testWithCronReturnsChangedCopy(): void
    {
        $job = new Job('job', static fn () => null);
        $changed = $job->withCron('0 0 * * *');

        self::assertNotSame($job, $changed);
        self::assertSame('* * * * *', $job->cron);
        self::assertSame('0 0 * * *', $changed->cron);
        self::assertSame($job->name, $changed->name);
        self::assertSame($job->callback, $changed->callback);
    }

    public function testWithCronRejectsInvalidCron(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Job('job', static fn () => null))->withCron('invalid');
    }

    public function testWithMutexReturnsChangedCopy(): void
    {
        $mutex = new FileMutex($this->temporaryDirectory());
        $job = new Job('job', static fn () => null, '0 0 * * *');
        $changed = $job->withMutex($mutex);

        self::assertNull($job->mutex);
        self::assertSame($mutex, $changed->mutex);
        self::assertSame('0 0 * * *', $changed->cron);
        self::assertInstanceOf(Mutex::class, $changed->mutex);
    }
}
