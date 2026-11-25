<?php

namespace CronRunner\Adapters\Mutex;

use CronRunner\Interfaces\Mutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;

/**
 * Lets Laravel's scheduler lock events with given Mutex.
 */
final class LaravelMutexAdapter implements EventMutex
{
    /**
     * The Mutex implementation.
     *
     * @var \CronRunner\Interfaces\Mutex
     */
    private Mutex $mutex;

    /**
     * Tokens of mutexes created by this process, by mutex name. Laravel's Event has no place to keep them.
     *
     * @var array<string, string>
     */
    private array $tokens = [];

    /**
     * @param  \CronRunner\Interfaces\Mutex  $mutex
     */
    public function __construct(Mutex $mutex)
    {
        $this->mutex = $mutex;
    }

    public function create(Event $event): bool
    {
        // Laravel keeps the lock lifetime in minutes, set by withoutOverlapping($expiresAt).
        $token = $this->mutex->create($event->mutexName(), $event->expiresAt * 60);

        if ($token === null) {
            return false;
        }

        $this->tokens[$event->mutexName()] = $token;
        return true;
    }

    public function exists(Event $event): bool
    {
        return $this->mutex->exists($event->mutexName());
    }

    public function forget(Event $event): void
    {
        $name = $event->mutexName();

        if (!isset($this->tokens[$name])) {
            return;
        }

        $this->mutex->remove($name, $this->tokens[$name]);
        unset($this->tokens[$name]);
    }
}
