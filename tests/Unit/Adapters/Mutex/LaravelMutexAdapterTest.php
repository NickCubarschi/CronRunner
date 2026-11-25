<?php

namespace CronRunner\Tests\Unit\Adapters\Mutex;

use CronRunner\Adapters\Mutex\LaravelMutexAdapter;
use CronRunner\Tests\Doubles\InMemoryMutex;
use CronRunner\Tests\TestCase;
use Illuminate\Console\Scheduling\CallbackEvent;

final class LaravelMutexAdapterTest extends TestCase
{
    private function event(LaravelMutexAdapter $adapter, string $name = 'job'): CallbackEvent
    {
        return (new CallbackEvent($adapter, static fn () => null))->name($name);
    }

    public function testCreatesLockOnlyOnce(): void
    {
        $mutex = new InMemoryMutex();
        $first = new LaravelMutexAdapter($mutex);
        $second = new LaravelMutexAdapter($mutex);

        self::assertTrue($first->create($this->event($first)));
        self::assertFalse($second->create($this->event($second)));
    }

    public function testExistsForAnyProcess(): void
    {
        $mutex = new InMemoryMutex();
        $owner = new LaravelMutexAdapter($mutex);
        $other = new LaravelMutexAdapter($mutex);

        self::assertFalse($other->exists($this->event($other)));

        $owner->create($this->event($owner));

        // Laravel asks "is anyone running it?", so the lock is visible to adapters that don't own it.
        self::assertTrue($other->exists($this->event($other)));
    }

    public function testForgetRemovesOwnLock(): void
    {
        $mutex = new InMemoryMutex();
        $adapter = new LaravelMutexAdapter($mutex);
        $event = $this->event($adapter);

        $adapter->create($event);
        $adapter->forget($event);

        self::assertFalse($adapter->exists($event));
    }

    public function testForgetKeepsLockOfAnotherProcess(): void
    {
        $mutex = new InMemoryMutex();
        $owner = new LaravelMutexAdapter($mutex);
        $other = new LaravelMutexAdapter($mutex);

        $owner->create($this->event($owner));
        $other->forget($this->event($other));

        self::assertTrue($owner->exists($this->event($owner)));
    }

    public function testDifferentNamesHaveDifferentLocks(): void
    {
        $adapter = new LaravelMutexAdapter(new InMemoryMutex());

        self::assertTrue($adapter->create($this->event($adapter, 'first')));
        self::assertTrue($adapter->create($this->event($adapter, 'second')));
    }

    public function testLockLifetimeComesFromWithoutOverlapping(): void
    {
        $mutex = new InMemoryMutex();
        $adapter = new LaravelMutexAdapter($mutex);

        $adapter->create($this->event($adapter, 'default')->withoutOverlapping());
        $adapter->create($this->event($adapter, 'ten-minutes')->withoutOverlapping(10));

        // Laravel sets the lifetime in minutes, the mutex gets seconds.
        self::assertEquals([1440 * 60, 10 * 60], $mutex->ttls);
    }
}
