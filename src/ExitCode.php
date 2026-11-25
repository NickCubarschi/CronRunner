<?php

namespace CronRunner;

/**
 * Exit codes of index.php, which cron and container logs can tell apart.
 * Any other code (PHP's 255) means an unexpected error, i.e. a bug.
 */
final class ExitCode
{
    /**
     * All due jobs finished successfully, or none was due.
     */
    public const OK = 0;

    /**
     * At least one job failed, or its mutex did; the other jobs still ran.
     */
    public const JOB_FAILED = 1;

    /**
     * The scheduler itself failed; see SchedulerException.
     */
    public const SCHEDULER_FAILED = 2;

    /**
     * src/Configuration.php couldn't be loaded, e.g. an unknown adapter or an invalid job.
     */
    public const INVALID_CONFIGURATION = 3;
}
