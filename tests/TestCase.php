<?php

namespace CronRunner\Tests;

/**
 * Base for all tests: temporary directories, cron expressions relative to now and running PHP processes.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    /**
     * Empty directory, which is removed after the test.
     */
    protected function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/cron-runner-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    protected static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Cron expression, which isn't due now in UTC: it runs only 12 hours away from the current hour.
     */
    protected static function notDueCron(): string
    {
        $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('G');

        return '* ' . (($hour + 12) % 24) . ' * * *';
    }

    /**
     * Cron expression, which is due during the current and next hour in the given timezone.
     * The next hour is included, so the test doesn't fail when the hour changes while it runs.
     */
    protected static function dueInTimezoneCron(string $timezone): string
    {
        $hour = (int) (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('G');

        return sprintf('* %d,%d * * *', $hour, ($hour + 1) % 24);
    }

    /**
     * Run a PHP script in its own process and wait for it.
     *
     * @param  list<string>  $arguments  Arguments for the PHP binary, e.g. a script path and its arguments
     * @param  array<string, string>  $environment  Variables added to the current environment
     * @return array{exitCode: int, output: string}
     */
    protected static function runPhp(array $arguments, array $environment = []): array
    {
        $process = self::startPhp($arguments, $environment);

        return self::waitFor($process);
    }

    /**
     * Start a PHP script in its own process without waiting for it.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     * @return array{process: resource, pipes: array<int, resource>}
     */
    protected static function startPhp(array $arguments, array $environment = []): array
    {
        $process = proc_open(
            [PHP_BINARY, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            self::projectRoot(),
            array_merge(getenv(), $environment),
        );

        if ($process === false) {
            self::fail('Unable to start PHP process');
        }

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * Wait until a process from startPhp() finishes.
     *
     * @param  array{process: resource, pipes: array<int, resource>}  $process
     * @return array{exitCode: int, output: string}
     */
    protected static function waitFor(array $process): array
    {
        $output = stream_get_contents($process['pipes'][1]);
        fclose($process['pipes'][1]);

        return ['exitCode' => proc_close($process['process']), 'output' => (string) $output];
    }

    /**
     * Wait until the file exists, so a background process is known to have reached a certain point.
     */
    protected static function waitForFile(string $path, float $timeout = 15.0): void
    {
        $deadline = microtime(true) + $timeout;

        while (!file_exists($path)) {
            if (microtime(true) > $deadline) {
                self::fail("File {$path} wasn't created within {$timeout} seconds");
            }

            usleep(50_000);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
