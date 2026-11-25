<?php

namespace CronRunner\Tests\Integration;

use CronRunner\ExitCode;
use CronRunner\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * index.php with src/Configuration.php, run the same way as from crontab.
 */
final class EntryPointTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function adapters(): array
    {
        return ['laravel' => ['laravel'], 'crunz' => ['crunz']];
    }

    #[DataProvider('adapters')]
    public function testRunsConfiguredJob(string $adapter): void
    {
        $result = self::runPhp(['index.php'], ['CRON_RUNNER_ADAPTER' => $adapter]);

        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertStringContainsString('Task finished', $result['output']);
    }

    public function testDefaultsToLaravel(): void
    {
        $result = self::runPhp(['index.php'], ['CRON_RUNNER_ADAPTER' => '']);

        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertStringContainsString('Task finished', $result['output']);
    }

    public function testInvalidConfigurationGivesOneLineAndItsExitCode(): void
    {
        $result = self::runPhp(['index.php'], ['CRON_RUNNER_ADAPTER' => 'cron']);

        self::assertSame(ExitCode::INVALID_CONFIGURATION, $result['exitCode']);
        self::assertSame(
            'Invalid configuration: Unknown CRON_RUNNER_ADAPTER "cron", use "laravel" or "crunz".' . PHP_EOL,
            $result['output'],
        );
    }

    #[DataProvider('adapters')]
    public function testFailingMutexFailsItsJobWithOneLine(string $adapter): void
    {
        // The configured FileMutex keeps its locks in the temp directory; a regular file in its place makes it fail.
        $temp = $this->temporaryDirectory();
        touch($temp . '/cron-runner-mutex');

        $result = self::runPhp(['index.php'], ['CRON_RUNNER_ADAPTER' => $adapter, 'TMPDIR' => $temp]);

        self::assertSame(ExitCode::JOB_FAILED, $result['exitCode'], $result['output']);
        self::assertStringStartsWith("Job \"task-finished\" failed: Can't open mutex guard file", $result['output']);
        self::assertSame(1, substr_count($result['output'], PHP_EOL), 'Only one line, no stack trace');
    }

    public function testSchedulerFailureGivesOneLineAndItsExitCode(): void
    {
        // Crunz refuses an empty cache directory, which makes the scheduler itself fail.
        $result = self::runPhp(['index.php'], ['CRON_RUNNER_ADAPTER' => 'crunz', 'CRUNZ_BASE_CACHE_DIR' => ' ']);

        self::assertSame(ExitCode::SCHEDULER_FAILED, $result['exitCode'], $result['output']);
        self::assertStringStartsWith('Scheduler failed: ', $result['output']);
        self::assertSame(1, substr_count($result['output'], PHP_EOL), 'Only one line, no stack trace');
    }

    public function testTimezoneComesFromPhpDefault(): void
    {
        $code = 'require "vendor/autoload.php"; require "src/Configuration.php"; echo CronRunner\TIMEZONE;';

        $result = self::runPhp(['-d', 'date.timezone=Asia/Tokyo', '-r', $code]);

        self::assertSame(0, $result['exitCode'], $result['output']);
        self::assertSame('Asia/Tokyo', $result['output']);
    }
}
