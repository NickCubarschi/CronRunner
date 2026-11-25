<?php

namespace CronRunner\Tests\Doubles;

use CronRunner\Interfaces\Adapter;
use CronRunner\Job;

/**
 * Adapter, which only records the jobs it got.
 */
final class RecordingAdapter implements Adapter
{
    /**
     * @var list<\CronRunner\Job>|null
     */
    public ?array $jobs = null;

    public function __construct(private readonly int $exitCode = 0)
    {
    }

    public function scheduleRun(Job ...$jobs): int
    {
        $this->jobs = array_values($jobs);

        return $this->exitCode;
    }
}
