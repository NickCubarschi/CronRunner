<?php

namespace CronRunner;

use CronRunner\Mutex\FileMutex;

// Timezone, in which cron expressions of all jobs are evaluated, whichever scheduler runs them.
// It's PHP's default timezone (date.timezone in php.ini), so jobs' own date functions use the same one.
define(__NAMESPACE__ . '\TIMEZONE', date_default_timezone_get());

$mutex = new FileMutex();

return new CronRunner(AdapterFactory::fromEnvironment(TIMEZONE))
    ->run(function (): void {
        echo("Task finished" . PHP_EOL);
    }, 'task-finished')
    ->cron("* * * * *")
    ->withMutex($mutex);
