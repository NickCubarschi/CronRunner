<?php

namespace CronRunner\Tests\Unit\Exceptions;

use CronRunner\Exceptions\CronRunnerException;
use CronRunner\Exceptions\MutexException;
use CronRunner\Exceptions\SchedulerException;
use CronRunner\Tests\TestCase;

final class SchedulerExceptionTest extends TestCase
{
    public function testWrapsCauseAndKeepsItsMessage(): void
    {
        $cause = new \LogicException('Lock storage is full');

        $exception = SchedulerException::from($cause);

        self::assertSame('Scheduler failed: Lock storage is full', $exception->getMessage());
        self::assertSame($cause, $exception->getPrevious());
    }

    public function testTakesMessageFromOwnCauseWrappedByLibrary(): void
    {
        $own = new MutexException("Can't open mutex guard file");
        $library = new \RuntimeException('Failed to acquire the "crunz-1234" lock.', 0, $own);

        $exception = SchedulerException::from($library);

        self::assertSame("Scheduler failed: Can't open mutex guard file", $exception->getMessage());
        self::assertSame($library, $exception->getPrevious());
    }

    public function testAllOwnExceptionsShareOneType(): void
    {
        self::assertInstanceOf(CronRunnerException::class, SchedulerException::from(new \Exception()));
        self::assertInstanceOf(CronRunnerException::class, new MutexException());
    }
}
