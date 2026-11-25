<?php

namespace CronRunner\Tests\Doubles;

use CronRunner\Interfaces\Mutex;

/**
 * Mutex kept in memory, which records the lifetimes it was asked for.
 */
final class InMemoryMutex implements Mutex
{
    /**
     * @var array<string, string> Tokens by key
     */
    public array $locks = [];

    /**
     * @var list<float|null>
     */
    public array $ttls = [];

    public function create(string $key, ?float $ttl = null): ?string
    {
        $this->ttls[] = $ttl;

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
        if ($this->exists($key, $token)) {
            unset($this->locks[$key]);
        }
    }

    public function prolongExpiration(string $key, string $token, float $ttl): bool
    {
        return $this->exists($key, $token);
    }
}
