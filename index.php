<?php

// Runs the due jobs; meant to be called by crontab every minute. Exit codes are listed in src/ExitCode.php.

use CronRunner\Exceptions\SchedulerException;
use CronRunner\ExitCode;

require __DIR__ . '/vendor/autoload.php';

try {
    $runner = require __DIR__ . '/src/Configuration.php';
} catch (\Throwable $e) {
    fwrite(STDERR, 'Invalid configuration: ' . $e->getMessage() . PHP_EOL);
    exit(ExitCode::INVALID_CONFIGURATION);
}

try {
    exit($runner->scheduleRun());
} catch (SchedulerException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(ExitCode::SCHEDULER_FAILED);
}
