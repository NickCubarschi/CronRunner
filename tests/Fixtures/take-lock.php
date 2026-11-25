<?php

// Tries to create a mutex and prints "won", if it succeeded.
// Usage: php take-lock.php <mutex directory> <key>

use CronRunner\Mutex\FileMutex;

require __DIR__ . '/../../vendor/autoload.php';

[, $mutexDirectory, $key] = $argv;

if ((new FileMutex($mutexDirectory))->create($key) !== null) {
    echo 'won';
}
