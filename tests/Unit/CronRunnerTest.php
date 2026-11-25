<?php

namespace CronRunner\Tests\Unit;

use CronRunner\CronRunner;
use CronRunner\Job;
use CronRunner\Mutex\FileMutex;
use CronRunner\Tests\Doubles\RecordingAdapter;
use CronRunner\Tests\TestCase;

final class CronRunnerTest extends TestCase
{
    public function testPassesJobsToAdapterInOrderAndReturnsItsExitCode(): void
    {
        $adapter = new RecordingAdapter(3);
        $runner = (new CronRunner($adapter))
            ->run(static fn () => null, 'first')
            ->run(static fn () => null, 'second');

        self::assertSame(3, $runner->scheduleRun());
        self::assertSame(['first', 'second'], array_map(static fn (Job $job) => $job->name, $adapter->jobs ?? []));
    }

    public function testMethodsAreChainable(): void
    {
        $runner = new CronRunner(new RecordingAdapter());

        self::assertSame($runner, $runner->run(static fn () => null, 'job'));
        self::assertSame($runner, $runner->cron('0 * * * *'));
        self::assertSame($runner, $runner->withMutex(new FileMutex($this->temporaryDirectory())));
        self::assertSame($runner, $runner->add(new Job('other', static fn () => null)));
    }

    public function testCronAndMutexConfigureOnlyTheLastJob(): void
    {
        $mutex = new FileMutex($this->temporaryDirectory());
        $runner = (new CronRunner(new RecordingAdapter()))
            ->run(static fn () => null, 'first')
            ->run(static fn () => null, 'second')
            ->cron('0 * * * *')
            ->withMutex($mutex);

        [$first, $second] = $runner->jobs();

        self::assertSame('* * * * *', $first->cron);
        self::assertNull($first->mutex);
        self::assertSame('0 * * * *', $second->cron);
        self::assertSame($mutex, $second->mutex);
    }

    public function testAddsReadyMadeJob(): void
    {
        $job = new Job('job', static fn () => null, '@daily');
        $runner = (new CronRunner(new RecordingAdapter()))->add($job);

        self::assertSame([$job], $runner->jobs());
    }

    public function testCronAfterAddConfiguresTheAddedJob(): void
    {
        $runner = (new CronRunner(new RecordingAdapter()))
            ->add(new Job('job', static fn () => null))
            ->cron('@hourly');

        self::assertSame('@hourly', $runner->jobs()[0]->cron);
    }

    public function testRejectsDuplicateNameFromRun(): void
    {
        $runner = (new CronRunner(new RecordingAdapter()))->run(static fn () => null, 'job');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Job "job" is already defined');

        $runner->run(static fn () => null, 'job');
    }

    public function testRejectsDuplicateNameFromAdd(): void
    {
        $runner = (new CronRunner(new RecordingAdapter()))->run(static fn () => null, 'job');

        $this->expectException(\InvalidArgumentException::class);

        $runner->add(new Job('job', static fn () => null));
    }

    public function testCronBeforeRunFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Call run() or add() before configuring a job');

        (new CronRunner(new RecordingAdapter()))->cron('* * * * *');
    }

    public function testWithMutexBeforeRunFails(): void
    {
        $this->expectException(\LogicException::class);

        (new CronRunner(new RecordingAdapter()))->withMutex(new FileMutex($this->temporaryDirectory()));
    }

    public function testInvalidCronFailsWhileConfiguring(): void
    {
        $runner = (new CronRunner(new RecordingAdapter()))->run(static fn () => null, 'job');

        $this->expectException(\InvalidArgumentException::class);

        $runner->cron('every minute');
    }

    public function testRunsWithoutJobs(): void
    {
        $adapter = new RecordingAdapter();

        self::assertSame(0, (new CronRunner($adapter))->scheduleRun());
        self::assertSame([], $adapter->jobs);
    }
}
