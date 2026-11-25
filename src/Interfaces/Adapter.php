<?php

namespace CronRunner\Interfaces;

use CronRunner\Job;

/**
 * Runs jobs through one scheduler library, so the libraries can be switched.
 */
interface Adapter
{
    /**
     * Run the jobs, which are due. Meant to be called by crontab every minute.
     * A failing job doesn't stop the others; it only makes the exit code \CronRunner\ExitCode::JOB_FAILED.
     * The same applies when a job's mutex fails: only that job is reported as failed (and skipped, if its lock
     * couldn't be taken).
     *
     * @return int \CronRunner\ExitCode::OK or \CronRunner\ExitCode::JOB_FAILED
     *
     * @throws \CronRunner\Exceptions\SchedulerException If the scheduler itself fails. Adapters never let their
     *                                                   libraries' exceptions out, so callers don't depend on them.
     */
    public function scheduleRun(Job ...$jobs): int;
}
