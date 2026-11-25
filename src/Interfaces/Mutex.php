<?php

namespace CronRunner\Interfaces;

/**
 * Methods throw \CronRunner\Exceptions\MutexException, if the mutex storage can't be used.
 */
interface Mutex
{
    /**
     * Create mutex, if it doesn't exist for given key.
     *
     * @param  float|null  $ttl  Time to live in seconds, or null for the implementation's default
     * @return string|null Token proving ownership of the mutex, or null if it's already held
     */
    public function create(string $key, ?float $ttl = null): ?string;

    /**
     * Return, if mutex exists under the key. With a token, only if it's owned by that token.
     *
     * @return bool
     */
    public function exists(string $key, ?string $token = null): bool;

    /**
     * Remove mutex by key, if it's owned by the token.
     *
     * @return void
     */
    public function remove(string $key, string $token): void;

    /**
     * Prolong expiration of mutex by key, if it's owned by the token.
     *
     * @return bool Whether the mutex was prolonged
     */
    public function prolongExpiration(string $key, string $token, float $ttl): bool;
}
