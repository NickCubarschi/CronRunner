<?php

namespace CronRunner\Adapters\Mutex;

use CronRunner\Exceptions\MutexException;
use CronRunner\Interfaces\Mutex;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Lets Crunz lock one job with given Mutex, through Symfony's lock store interface.
 */
final class CrunzMutexAdapter implements PersistingStoreInterface
{
    /**
     * The Mutex implementation.
     *
     * @var \CronRunner\Interfaces\Mutex
     */
    private Mutex $mutex;

    /**
     * Job name, which prefixes the mutex key.
     *
     * @var string
     */
    private string $name;

    /**
     * First failure of the mutex storage, if there was one.
     */
    private ?MutexException $failure = null;

    /**
     * @param  \CronRunner\Interfaces\Mutex  $mutex
     */
    public function __construct(Mutex $mutex, string $name)
    {
        $this->mutex = $mutex;
        $this->name = $name;
    }

    /**
     * First failure of the mutex storage, which CrunzAdapter reports after the run.
     */
    public function failure(): ?MutexException
    {
        return $this->failure;
    }

    // Mutex token is kept in the key's state, the same way Symfony's own stores keep theirs.
    public function save(Key $key): void
    {
        try {
            $token = $this->mutex->create($this->key($key));
        } catch (MutexException $e) {
            $this->failure ??= $e;
            $token = null;
        }

        // Reported as held, so Crunz skips the job.
        if ($token === null) {
            throw new LockConflictedException();
        }

        $key->setState(self::class, $token);
    }

    public function exists(Key $key): bool
    {
        if (!$key->hasState(self::class)) {
            return false;
        }

        try {
            return $this->mutex->exists($this->key($key), $key->getState(self::class));
        } catch (MutexException $e) {
            $this->failure ??= $e;
            return false;
        }
    }

    public function delete(Key $key): void
    {
        if (!$key->hasState(self::class)) {
            return;
        }

        try {
            $this->mutex->remove($this->key($key), $key->getState(self::class));
        } catch (MutexException $e) {
            // The lock stays until it expires; the job is reported as failed.
            $this->failure ??= $e;
        }

        $key->removeState(self::class);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        if (!$key->hasState(self::class)) {
            throw new LockConflictedException();
        }

        try {
            $prolonged = $this->mutex->prolongExpiration($this->key($key), $key->getState(self::class), $ttl);
        } catch (MutexException $e) {
            // The job keeps running, but is reported as failed, as its lock couldn't be kept.
            $this->failure ??= $e;
            return;
        }

        if (!$prolonged) {
            throw new LockConflictedException();
        }
    }

    // Crunz builds its key from the closure's source code, and CrunzAdapter wraps every job in the same closure,
    // so without the name all jobs would share one lock.
    private function key(Key $key): string
    {
        return $this->name . ':' . $key;
    }
}
