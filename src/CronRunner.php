<?php

namespace CronRunner;

use CronRunner\Interfaces\Adapter;
use CronRunner\Interfaces\Mutex;

/**
 * Collects jobs and runs them through the chosen scheduler adapter.
 */
class CronRunner
{
    /**
     * CronRunner adapter.
     *
     * @var \CronRunner\Interfaces\Adapter
     */
    private Adapter $adapter;

    /**
     * Defined jobs by their name.
     *
     * @var array<string, \CronRunner\Job>
     */
    private array $jobs = [];

    /**
     * Name of the last added job, which cron() and withMutex() configure.
     */
    private ?string $current = null;

    /**
     * @param  \CronRunner\Interfaces\Adapter  $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Add a job made from the anonymous function; cron() and withMutex() then configure it.
     */
    public function run(\Closure $job, string $name): static
    {
        return $this->add(new Job($name, $job));
    }

    /**
     * Add a ready-made job; cron() and withMutex() then configure it.
     */
    public function add(Job $job): static
    {
        if (isset($this->jobs[$job->name])) {
            throw new \InvalidArgumentException("Job \"{$job->name}\" is already defined, job names must be unique");
        }

        $this->jobs[$job->name] = $job;
        $this->current = $job->name;
        return $this;
    }

    /**
     * Set the cron expression of the last added job.
     */
    public function cron(string $cronString): static
    {
        $this->jobs[$this->current()] = $this->jobs[$this->current()]->withCron($cronString);
        return $this;
    }

    /**
     * Prevent overlapping runs of the last added job with the mutex.
     */
    public function withMutex(Mutex $mutex): static
    {
        $this->jobs[$this->current()] = $this->jobs[$this->current()]->withMutex($mutex);
        return $this;
    }

    /**
     * Run the due jobs and return the \CronRunner\ExitCode.
     */
    public function scheduleRun(): int
    {
        return $this->adapter->scheduleRun(...$this->jobs());
    }

    /**
     * @return list<\CronRunner\Job>
     */
    public function jobs(): array
    {
        return array_values($this->jobs);
    }

    /**
     * Name of the last added job, which cron() and withMutex() configure.
     */
    private function current(): string
    {
        if ($this->current === null) {
            throw new \LogicException('Call run() or add() before configuring a job');
        }

        return $this->current;
    }
}
