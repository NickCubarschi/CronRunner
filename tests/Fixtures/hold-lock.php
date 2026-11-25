<?php

// Runs one job with a mutex, which creates a file once it has started and then keeps running, so its lock stays held.
// Usage: php hold-lock.php <adapter> <job name> <mutex directory> <started file> <seconds>

use CronRunner\AdapterFactory;
use CronRunner\Job;
use CronRunner\Mutex\FileMutex;

require __DIR__ . '/../../vendor/autoload.php';

[, $adapter, $name, $mutexDirectory, $startedFile, $seconds] = $argv;

$job = new Job(
    $name,
    static function () use ($startedFile, $seconds): void {
        touch($startedFile);
        sleep((int) $seconds);
    },
    '* * * * *',
    new FileMutex($mutexDirectory),
);

exit(AdapterFactory::create($adapter, 'UTC')->scheduleRun($job));
