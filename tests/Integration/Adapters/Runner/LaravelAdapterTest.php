<?php

namespace CronRunner\Tests\Integration\Adapters\Runner;

use Carbon\Carbon;
use CronRunner\Adapters\Runner\LaravelAdapter;
use CronRunner\Job;
use CronRunner\Tests\TestCase;

/**
 * Behaviour specific to Laravel, which runs jobs one after another in this process.
 * Laravel reads the time through Carbon, so its test clock can move time while a job runs.
 */
final class LaravelAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testDueJobsAreDecidedBeforeAnyRuns(): void
    {
        $directory = $this->temporaryDirectory();
        Carbon::setTestNow('2026-11-24 10:05:00');

        $slow = new Job('slow', static function (): void {
            // Runs for two minutes, so it finishes in a later minute than the run started.
            Carbon::setTestNow('2026-11-24 10:07:00');
        });
        $dueAtStart = new Job('due-at-start', static function () use ($directory): void {
            touch($directory . '/due-at-start');
        }, '5 * * * *');
        $dueOnlyLater = new Job('due-only-later', static function () use ($directory): void {
            touch($directory . '/due-only-later');
        }, '7 * * * *');

        (new LaravelAdapter(new \DateTimeZone('UTC')))->scheduleRun($slow, $dueAtStart, $dueOnlyLater);

        self::assertFileExists($directory . '/due-at-start');
        self::assertFileDoesNotExist($directory . '/due-only-later');
    }
}
