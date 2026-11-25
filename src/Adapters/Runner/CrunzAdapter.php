<?php

namespace CronRunner\Adapters\Runner;

use CronRunner\Adapters\Mutex\CrunzMutexAdapter;
use CronRunner\Exceptions\SchedulerException;
use CronRunner\ExitCode;
use CronRunner\Interfaces\Adapter;
use CronRunner\Job;
use Crunz\Application;
use Crunz\CacheDirectoryFactory\CacheDirectoryFactory;
use Crunz\EventRunner;
use Crunz\Schedule;
use Crunz\Task\Timezone;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Runs Crunz in this process, the same way its `schedule:run` command does, so the given jobs are the ones that run.
 */
final class CrunzAdapter implements Adapter
{
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
    }

    public function scheduleRun(Job ...$jobs): int
    {
        // Jobs run in their own processes and can't throw here, and CrunzMutexAdapter only records mutex failures,
        // so anything thrown is the scheduler's failure: Crunz's or Symfony's (lock, container, process).
        try {
            return $this->run(...$jobs);
        } catch (\Throwable $e) {
            throw SchedulerException::from($e);
        }
    }

    /**
     * Run the due jobs through Crunz's EventRunner and return the exit code.
     */
    private function run(Job ...$jobs): int
    {
        $applicationFile = (new \ReflectionClass(Application::class))->getFileName();
        if ($applicationFile === false) {
            throw new \LogicException("Can't find Crunz's installation directory");
        }
        $crunzRoot = dirname($applicationFile, 2);

        // Crunz runs every closure in its own process through its CLI binary, which normally defines this constant.
        if (!defined('CRUNZ_BIN')) {
            define('CRUNZ_BIN', $crunzRoot . '/crunz');
        }

        $container = $this->container($crunzRoot);

        // Keep only the due events, like `schedule:run` does.
        [$schedule, $locks] = $this->schedule(...$jobs);
        $schedule->events($schedule->dueEvents($this->timezone));

        if (count($schedule->events()) === 0) {
            // A job, whose lock couldn't be taken, isn't due anymore, but it still failed.
            return $this->reportLockFailures($locks) ? ExitCode::JOB_FAILED : ExitCode::OK;
        }

        // Closure processes start in parallel and would race to create Crunz's cache directory, so it's created first.
        $cacheDirectory = (new CacheDirectoryFactory())->generate()->toString();
        if (!is_dir($cacheDirectory)) {
            @mkdir($cacheDirectory, 0777, true);
        }

        // Crunz reports failed jobs but always finishes successfully, so failures are tracked to return the exit code.
        $failed = false;
        $schedule->onError(function () use (&$failed): void {
            $failed = true;
        });

        /** @var \Crunz\EventRunner $eventRunner */
        $eventRunner = $container->get(EventRunner::class);
        /** @var \Symfony\Component\Console\Output\OutputInterface $output */
        $output = $container->get(OutputInterface::class);
        $eventRunner->handle($output, [$schedule]);

        $failed = $this->reportLockFailures($locks) || $failed;

        return $failed ? ExitCode::JOB_FAILED : ExitCode::OK;
    }

    /**
     * Report jobs, whose mutex storage failed while taking, keeping or releasing their lock.
     *
     * @param  array<string, CrunzMutexAdapter>  $locks  Lock stores by job name
     * @return bool Whether any job failed this way
     */
    private function reportLockFailures(array $locks): bool
    {
        $failed = false;

        foreach ($locks as $name => $lock) {
            if ($lock->failure() !== null) {
                fwrite(STDERR, "Job \"{$name}\" failed: {$lock->failure()->getMessage()}" . PHP_EOL);
                $failed = true;
            }
        }

        return $failed;
    }

    /**
     * Turn the jobs into a Crunz schedule.
     *
     * @return array{Schedule, array<string, CrunzMutexAdapter>} The schedule and lock stores of jobs with a mutex, by job name
     */
    private function schedule(Job ...$jobs): array
    {
        $schedule = new Schedule();
        $locks = [];
        $timezone = $this->timezone->getName();

        foreach ($jobs as $job) {
            // Crunz runs each job in its own process, which doesn't load the configuration, so the timezone is set there.
            $callback = $job->callback;
            $wrapped = static function () use ($timezone, $callback): mixed {
                date_default_timezone_set($timezone);
                return $callback();
            };

            $event = $schedule->run($wrapped)
                ->description($job->name)
                ->cron($job->cron);

            if ($job->mutex !== null) {
                $locks[$job->name] = new CrunzMutexAdapter($job->mutex, $job->name);
                $event->preventOverlapping($locks[$job->name]);
            }
        }

        return [$schedule, $locks];
    }

    /**
     * Build Crunz's own service container, with the services this adapter needs made public.
     * Crunz's timezone service is replaced, so Crunz uses this adapter's timezone instead of one from crunz.yml.
     */
    private function container(string $crunzRoot): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new PhpFileLoader($container, new FileLocator($crunzRoot . '/config')))->load('services.php');

        $container->getDefinition(EventRunner::class)->setPublic(true);
        $container->getDefinition(Timezone::class)->setSynthetic(true)->setPublic(true);

        $container->compile();

        // Parent constructor isn't called, because its dependencies are used only to read the timezone from config.
        $container->set(Timezone::class, new class ($this->timezone) extends Timezone {
            public function __construct(private readonly \DateTimeZone $fixedTimezone)
            {
            }

            public function timezoneForComparisons(): \DateTimeZone
            {
                return $this->fixedTimezone;
            }
        });

        return $container;
    }
}
