<?php

namespace CronRunner\Exceptions;

/**
 * The scheduler itself couldn't run the jobs, e.g. its process handling failed.
 * A failing job, or a failing mutex of one job, isn't reported this way, but by the exit code of Adapter::scheduleRun().
 */
final class SchedulerException extends \RuntimeException implements CronRunnerException
{
    /**
     * The message is taken from this project's own exception among the causes, if there's one (e.g. MutexException),
     * because libraries wrap it differently, so the same failure reads the same with every scheduler.
     * The whole chain is kept as the previous exception for debugging.
     */
    public static function from(\Throwable $previous): self
    {
        $reason = $previous;
        for ($cause = $previous; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof CronRunnerException) {
                $reason = $cause;
                break;
            }
        }

        return new self('Scheduler failed: ' . $reason->getMessage(), 0, $previous);
    }
}
