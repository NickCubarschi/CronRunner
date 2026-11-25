<?php

namespace CronRunner\Tests\Integration\Adapters\Runner;

use CronRunner\AdapterFactory;
use CronRunner\ExitCode;
use CronRunner\Interfaces\Adapter;
use CronRunner\Interfaces\Mutex;
use CronRunner\Job;
use CronRunner\Mutex\FileMutex;
use CronRunner\Tests\Doubles\UncreatableMutex;
use CronRunner\Tests\Doubles\UnreleasableMutex;
use CronRunner\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Behaviour, which both schedulers must share, so switching between them changes nothing.
 * Every test runs once with Laravel and once with Crunz.
 *
 * Jobs record that they ran by creating files, because Crunz runs each job in its own process.
 * Closures are static, so Crunz can serialize them without the test case.
 */
final class AdapterContractTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->temporaryDirectory();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adapters(): array
    {
        return ['laravel' => ['laravel'], 'crunz' => ['crunz']];
    }

    private function adapter(string $name, string $timezone = 'UTC'): Adapter
    {
        return AdapterFactory::create($name, $timezone);
    }

    /**
     * Job, which appends a line to the file each time it runs.
     */
    private function job(string $name, string $file, string $cron = '* * * * *', ?Mutex $mutex = null): Job
    {
        return new Job($name, static function () use ($file): void {
            file_put_contents($file, "ran\n", FILE_APPEND);
        }, $cron, $mutex);
    }

    private static function runCount(string $file): int
    {
        return file_exists($file) ? substr_count((string) file_get_contents($file), "ran\n") : 0;
    }

    #[DataProvider('adapters')]
    public function testRunsDueJob(string $adapter): void
    {
        $file = $this->directory . '/job';

        self::assertSame(0, $this->adapter($adapter)->scheduleRun($this->job('job', $file)));
        self::assertSame(1, self::runCount($file));
    }

    #[DataProvider('adapters')]
    public function testRunsEveryKindOfAcceptedJob(string $adapter): void
    {
        // Every callback Job accepts must work with both schedulers, see JobTest for the rejected ones.
        $directory = $this->directory;
        $paths = ['closure' => $directory . '/closure', 'arrow' => $directory . '/arrow'];

        $exitCode = $this->adapter($adapter)->scheduleRun(
            new Job('closure', function () use ($paths): void {
                touch($paths['closure']);
            }),
            new Job('arrow', static fn () => touch($paths['arrow'])),
        );

        self::assertSame(0, $exitCode);
        self::assertFileExists($paths['closure']);
        self::assertFileExists($paths['arrow']);
    }

    #[DataProvider('adapters')]
    public function testSkipsJobWhichIsNotDue(string $adapter): void
    {
        $file = $this->directory . '/job';

        self::assertSame(0, $this->adapter($adapter)->scheduleRun($this->job('job', $file, self::notDueCron())));
        self::assertSame(0, self::runCount($file));
    }

    #[DataProvider('adapters')]
    public function testRunsAllDueJobsAndOnlyThem(string $adapter): void
    {
        $first = $this->directory . '/first';
        $second = $this->directory . '/second';
        $notDue = $this->directory . '/not-due';

        $exitCode = $this->adapter($adapter)->scheduleRun(
            $this->job('first', $first),
            $this->job('not-due', $notDue, self::notDueCron()),
            $this->job('second', $second),
        );

        self::assertSame(0, $exitCode);
        self::assertSame(1, self::runCount($first));
        self::assertSame(1, self::runCount($second));
        self::assertSame(0, self::runCount($notDue));
    }

    #[DataProvider('adapters')]
    public function testRunsWithoutJobs(string $adapter): void
    {
        self::assertSame(0, $this->adapter($adapter)->scheduleRun());
    }

    #[DataProvider('adapters')]
    public function testFailingJobGivesExitCodeOneAndOthersStillRun(string $adapter): void
    {
        $file = $this->directory . '/job';
        $failing = new Job('failing', static function (): void {
            throw new \RuntimeException('Job failed on purpose');
        });

        $exitCode = $this->adapter($adapter)->scheduleRun($failing, $this->job('job', $file));

        self::assertSame(1, $exitCode);
        self::assertSame(1, self::runCount($file));
    }

    #[DataProvider('adapters')]
    public function testEvaluatesCronInAdapterTimezone(string $adapter): void
    {
        $file = $this->directory . '/job';
        $cron = self::dueInTimezoneCron('Asia/Tokyo');

        // Los Angeles is 16 or 17 hours behind Tokyo, so the same expression isn't due there.
        $this->adapter($adapter, 'America/Los_Angeles')->scheduleRun($this->job('job', $file, $cron));
        self::assertSame(0, self::runCount($file));

        $this->adapter($adapter, 'Asia/Tokyo')->scheduleRun($this->job('job', $file, $cron));
        self::assertSame(1, self::runCount($file));
    }

    #[DataProvider('adapters')]
    public function testMutexIsReleasedAfterJobFinishes(string $adapter): void
    {
        $file = $this->directory . '/job';
        $mutex = new FileMutex($this->directory . '/mutex');

        $this->adapter($adapter)->scheduleRun($this->job('job', $file, mutex: $mutex));
        $this->adapter($adapter)->scheduleRun($this->job('job', $file, mutex: $mutex));

        self::assertSame(2, self::runCount($file));
    }

    #[DataProvider('adapters')]
    public function testMutexSkipsJobWhileSameJobRunsElsewhere(string $adapter): void
    {
        $mutexDirectory = $this->directory . '/mutex';
        $started = $this->directory . '/started';
        $file = $this->directory . '/job';

        // Another process runs the same job for a few seconds and holds its lock meanwhile.
        $other = self::startPhp(['tests/Fixtures/hold-lock.php', $adapter, 'job', $mutexDirectory, $started, '3']);
        self::waitForFile($started);

        $exitCode = $this->adapter($adapter)->scheduleRun($this->job('job', $file, mutex: new FileMutex($mutexDirectory)));

        $result = self::waitFor($other);
        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertSame(0, $exitCode);
        self::assertSame(0, self::runCount($file));

        // Once the other process has finished, the job runs again.
        $this->adapter($adapter)->scheduleRun($this->job('job', $file, mutex: new FileMutex($mutexDirectory)));
        self::assertSame(1, self::runCount($file));
    }

    /**
     * Ways a job's mutex can fail, and whether the job still runs, as its lock was taken before the failure.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function mutexFailures(): array
    {
        $cases = [];
        foreach (['laravel', 'crunz'] as $adapter) {
            $cases["{$adapter}, unusable storage"] = [$adapter, 'unusable storage', false];
            $cases["{$adapter}, lock can't be taken"] = [$adapter, "lock can't be taken", false];
            $cases["{$adapter}, lock can't be released"] = [$adapter, "lock can't be released", true];
        }

        return $cases;
    }

    #[DataProvider('mutexFailures')]
    public function testFailingMutexFailsOnlyItsJob(string $adapter, string $failure, bool $jobRuns): void
    {
        $broken = $this->directory . '/broken';
        $other = $this->directory . '/other';

        // A regular file where the directory should be, so a FileMutex there fails on every call.
        touch($this->directory . '/not-a-directory');

        $mutex = match ($failure) {
            'unusable storage' => new FileMutex($this->directory . '/not-a-directory'),
            "lock can't be taken" => new UncreatableMutex(),
            "lock can't be released" => new UnreleasableMutex(),
            default => self::fail("Unknown mutex failure \"{$failure}\""),
        };

        // Listed first, so a failure that stopped the run would also stop the other job.
        $exitCode = $this->adapter($adapter)->scheduleRun(
            $this->job('broken', $broken, mutex: $mutex),
            $this->job('other', $other),
        );

        self::assertSame(ExitCode::JOB_FAILED, $exitCode);
        self::assertSame($jobRuns ? 1 : 0, self::runCount($broken));
        self::assertSame(1, self::runCount($other));
    }

    #[DataProvider('adapters')]
    public function testMutexOfOneJobDoesNotBlockAnotherJob(string $adapter): void
    {
        $mutexDirectory = $this->directory . '/mutex';
        $started = $this->directory . '/started';
        $file = $this->directory . '/job';

        $other = self::startPhp(['tests/Fixtures/hold-lock.php', $adapter, 'other', $mutexDirectory, $started, '3']);
        self::waitForFile($started);

        $this->adapter($adapter)->scheduleRun($this->job('job', $file, mutex: new FileMutex($mutexDirectory)));

        self::waitFor($other);
        self::assertSame(1, self::runCount($file));
    }
}
