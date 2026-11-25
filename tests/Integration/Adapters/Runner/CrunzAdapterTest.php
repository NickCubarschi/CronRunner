<?php

namespace CronRunner\Tests\Integration\Adapters\Runner;

use CronRunner\Adapters\Runner\CrunzAdapter;
use CronRunner\Job;
use CronRunner\Tests\TestCase;

/**
 * Behaviour specific to Crunz, which runs each job in its own process.
 */
final class CrunzAdapterTest extends TestCase
{
    public function testJobProcessUsesAdapterTimezone(): void
    {
        $file = $this->temporaryDirectory() . '/timezone';
        $job = new Job('job', static function () use ($file): void {
            file_put_contents($file, date_default_timezone_get());
        });

        (new CrunzAdapter(new \DateTimeZone('Asia/Tokyo')))->scheduleRun($job);

        self::assertStringEqualsFile($file, 'Asia/Tokyo');
    }

    public function testRunsJobsGivenToIt(): void
    {
        $directory = $this->temporaryDirectory();
        $file = $directory . '/given';

        (new CrunzAdapter(new \DateTimeZone('UTC')))->scheduleRun(new Job('given', static function () use ($file): void {
            touch($file);
        }));

        self::assertFileExists($file);
        self::assertSame(['.', '..', 'given'], scandir($directory));
    }
}
