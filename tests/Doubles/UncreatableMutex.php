<?php

namespace CronRunner\Tests\Doubles;

use CronRunner\Exceptions\MutexException;
use CronRunner\Interfaces\Mutex;

/**
 * Mutex, whose locks look free, but fail when a scheduler tries to take one.
 */
final class UncreatableMutex implements Mutex
{
    public function create(string $key, ?float $ttl = null): ?string
    {
        throw new MutexException('Lock storage is full');
    }

    public function exists(string $key, ?string $token = null): bool
    {
        return false;
    }

    public function remove(string $key, string $token): void
    {
    }

    public function prolongExpiration(string $key, string $token, float $ttl): bool
    {
        return false;
    }
}
