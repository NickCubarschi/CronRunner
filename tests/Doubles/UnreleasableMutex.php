<?php

namespace CronRunner\Tests\Doubles;

use CronRunner\Exceptions\MutexException;
use CronRunner\Interfaces\Mutex;

/**
 * In-memory mutex, whose locks can be taken, but fail when they're released.
 */
final class UnreleasableMutex implements Mutex
{
    /**
     * @var array<string, string> Tokens by key
     */
    private array $locks = [];

    public function create(string $key, ?float $ttl = null): ?string
    {
        if (isset($this->locks[$key])) {
            return null;
        }

        return $this->locks[$key] = bin2hex(random_bytes(8));
    }

    public function exists(string $key, ?string $token = null): bool
    {
        return isset($this->locks[$key]) && ($token === null || $this->locks[$key] === $token);
    }

    public function remove(string $key, string $token): void
    {
        throw new MutexException('Lock storage went read-only');
    }

    public function prolongExpiration(string $key, string $token, float $ttl): bool
    {
        return $this->exists($key, $token);
    }
}
