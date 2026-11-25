<?php

namespace CronRunner\Adapters\Runner;

use CronRunner\Adapters\Mutex\LaravelMutexAdapter;
use CronRunner\Exceptions\MutexException;
use CronRunner\Exceptions\SchedulerException;
use CronRunner\ExitCode;
use CronRunner\Interfaces\Adapter;
use CronRunner\Job;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Container\Container;

/**
 * Runs the jobs through Laravel's scheduler events in this process, without a full Laravel application.
 */
final class LaravelAdapter implements Adapter
{
    /**
     * Minimal stand-in for the Laravel application, which the scheduler events expect.
     *
     * @var \Illuminate\Container\Container
     */
    private Container $app;

    /**
     * Timezone, in which cron expressions are evaluated.
     *
     * @var \DateTimeZone
     */
    private \DateTimeZone $timezone;

    /**
     * @param  \DateTimeZone  $timezone  Timezone, in which cron expressions are evaluated
     */
    public function __construct(\DateTimeZone $timezone)
    {
        $this->timezone = $timezone;

        $this->app = new class extends Container {
            public function isDownForMaintenance(): bool
            {
                return false; // For now, for release, this needs to be wired.
            }

            /**
             * @param  string|array<string>  ...$environments
             */
            public function environment(string|array ...$environments): string
            {
                return 'production'; // For now, for release, this needs to be wired.
            }
        };
    }

    public function scheduleRun(Job ...$jobs): int
    {
        $exitCode = ExitCode::OK;

        // Laravel's and the mutex's exceptions are translated, so callers don't depend on the scheduler.
        try {
            // Due jobs are decided before any of them runs, like Laravel's schedule:run and Crunz do. Otherwise a job
            // running past the end of the minute would make the following jobs be checked against the next minute.
            $due = [];
            foreach ($jobs as $job) {
                $event = $this->event($job);

                // The stand-in isn't a full Application, but isDue() calls only isDownForMaintenance()
                // and environment() on it.
                // @phpstan-ignore argument.type
                if ($event->isDue($this->app)) {
                    $due[] = [$job, $event];
                }
            }
        } catch (\Throwable $e) {
            throw SchedulerException::from($e);
        }

        // One failing job shouldn't stop the others. That includes its mutex storage failing while the lock is checked,
        // taken or released, as only this job's lock is affected.
        foreach ($due as [$job, $event]) {
            // Filters, including the overlap check, are evaluated right before the run, so they see the current state.
            try {
                // The stand-in isn't a full Application, but filtersPass() only calls filters through
                //the container's call().
                // @phpstan-ignore argument.type
                $passes = $event->filtersPass($this->app);
            } catch (MutexException $e) {
                $this->reportFailure($job, $e);
                $exitCode = ExitCode::JOB_FAILED;
                continue;
            } catch (\Throwable $e) {
                throw SchedulerException::from($e);
            }

            if (!$passes) {
                continue;
            }

            try {
                $event->run($this->app);
            } catch (\Throwable $e) {
                $this->reportFailure($job, $e);
                $exitCode = ExitCode::JOB_FAILED;
            }
        }

        return $exitCode;
    }

    /**
     * Write the job's failure as one line to stderr.
     */
    private function reportFailure(Job $job, \Throwable $e): void
    {
        fwrite(STDERR, "Job \"{$job->name}\" failed: {$e->getMessage()}" . PHP_EOL);
    }

    /**
     * Turn the job into Laravel's scheduler event.
     */
    private function event(Job $job): CallbackEvent
    {
        // Laravel requires a mutex for every event; it's used only when overlapping is prevented.
        $noMutex = new class implements EventMutex {
            public function create(Event $event): bool
            {
                return true;
            }

            public function exists(Event $event): bool
            {
                return false;
            }

            public function forget(Event $event): void
            {
            }
        };

        // Laravel builds the mutex key from the event's name.
        $event = (new CallbackEvent($noMutex, $job->callback, [], $this->timezone))->name($job->name)->cron($job->cron);

        if ($job->mutex !== null) {
            $event->preventOverlapsUsing(new LaravelMutexAdapter($job->mutex));
            $event->withoutOverlapping();
        }

        return $event;
    }
}
