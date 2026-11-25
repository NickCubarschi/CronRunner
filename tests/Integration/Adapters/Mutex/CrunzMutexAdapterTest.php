<?php

namespace CronRunner\Tests\Integration\Adapters\Mutex;

use CronRunner\Adapters\Mutex\CrunzMutexAdapter;
use CronRunner\Exceptions\MutexException;
use CronRunner\Interfaces\Mutex;
use CronRunner\Mutex\FileMutex;
use CronRunner\Tests\Doubles\UncreatableMutex;
use CronRunner\Tests\Doubles\UnreleasableMutex;
use CronRunner\Tests\TestCase;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;

/**
 * Crunz uses the adapter through Symfony's lock component, so it's tested the same way.
 */
final class CrunzMutexAdapterTest extends TestCase
{
    private FileMutex $mutex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mutex = new FileMutex($this->temporaryDirectory());
    }

    private function store(string $name = 'job'): CrunzMutexAdapter
    {
        return new CrunzMutexAdapter($this->mutex, $name);
    }

    /**
     * Message of the recorded failure, which must exist.
     */
    private static function failureMessage(CrunzMutexAdapter $store): string
    {
        $failure = $store->failure();
        self::assertNotNull($failure);

        return $failure->getMessage();
    }

    public function testLockIsAcquiredOnlyOnceUntilReleased(): void
    {
        $factory = new LockFactory($this->store());
        $first = $factory->createLock('resource');
        $second = $factory->createLock('resource');

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());

        $first->release();

        self::assertTrue($second->acquire());
    }

    public function testLockWithTtlIsAcquiredAndRefreshed(): void
    {
        $lock = (new LockFactory($this->store()))->createLock('resource', 60);

        // Acquiring with a TTL makes Symfony prolong the lock through putOffExpiration().
        self::assertTrue($lock->acquire());
        $lock->refresh();
        self::assertTrue($lock->isAcquired());
    }

    public function testSaveFailsWhileHeldByAnotherKey(): void
    {
        $store = $this->store();
        $store->save(new Key('resource'));

        $this->expectException(LockConflictedException::class);

        $store->save(new Key('resource'));
    }

    public function testExistsOnlyForOwnerKey(): void
    {
        $store = $this->store();
        $owner = new Key('resource');
        $store->save($owner);

        self::assertTrue($store->exists($owner));
        self::assertFalse($store->exists(new Key('resource')));
    }

    public function testDeleteByOwnerReleasesLock(): void
    {
        $store = $this->store();
        $owner = new Key('resource');
        $store->save($owner);

        $store->delete($owner);

        self::assertFalse($store->exists($owner));
        $store->save(new Key('resource'));
        $this->addToAssertionCount(1);
    }

    public function testDeleteByOtherKeyKeepsLock(): void
    {
        $store = $this->store();
        $owner = new Key('resource');
        $store->save($owner);

        $store->delete(new Key('resource'));

        self::assertTrue($store->exists($owner));
    }

    public function testOwnerPutsOffExpiration(): void
    {
        $store = $this->store();
        $owner = new Key('resource');
        $store->save($owner);

        $store->putOffExpiration($owner, 60);

        self::assertTrue($store->exists($owner));
    }

    public function testOtherKeyCanNotPutOffExpiration(): void
    {
        $store = $this->store();
        $store->save(new Key('resource'));

        $this->expectException(LockConflictedException::class);

        $store->putOffExpiration(new Key('resource'), 60);
    }

    public function testJobNamesSeparateLocksOfSameResource(): void
    {
        // Crunz derives the resource from the closure's code, so different jobs can share it; the job name keeps them apart.
        $this->store('first')->save(new Key('resource'));
        $this->store('second')->save(new Key('resource'));

        $this->addToAssertionCount(1);
    }

    public function testRecordsNoFailureInNormalUse(): void
    {
        $store = $this->store();
        $lock = (new LockFactory($store))->createLock('resource', 60);

        $lock->acquire();
        $lock->refresh();
        $lock->release();

        self::assertNull($store->failure());
    }

    // Crunz takes, refreshes and releases locks inside one run loop, which an exception would stop for every job.

    public function testFailureToTakeLockIsRecordedAndReportedAsHeld(): void
    {
        $store = new CrunzMutexAdapter(new UncreatableMutex(), 'job');

        self::assertFalse((new LockFactory($store))->createLock('resource')->acquire());
        self::assertInstanceOf(MutexException::class, $store->failure());
    }

    public function testFailureOfUnusableStorageIsRecorded(): void
    {
        $file = $this->temporaryDirectory() . '/not-a-directory';
        touch($file);
        $store = new CrunzMutexAdapter(new FileMutex($file), 'job');

        self::assertFalse((new LockFactory($store))->createLock('resource')->acquire());
        self::assertStringContainsString("Can't open mutex guard file", self::failureMessage($store));
    }

    public function testFailureToReleaseLockIsRecordedWithoutThrowing(): void
    {
        $store = new CrunzMutexAdapter(new UnreleasableMutex(), 'job');
        $lock = (new LockFactory($store))->createLock('resource');
        $lock->acquire();

        $lock->release();

        self::assertSame('Lock storage went read-only', self::failureMessage($store));
    }

    public function testFailureToRefreshLockIsRecordedWithoutThrowing(): void
    {
        $mutex = new class implements Mutex {
            public function create(string $key, ?float $ttl = null): string
            {
                return 'token';
            }

            public function exists(string $key, ?string $token = null): bool
            {
                return true;
            }

            public function remove(string $key, string $token): void
            {
            }

            public function prolongExpiration(string $key, string $token, float $ttl): bool
            {
                throw new MutexException('Lock storage is full');
            }
        };
        $store = new CrunzMutexAdapter($mutex, 'job');
        $lock = (new LockFactory($store))->createLock('resource', 60);

        // Symfony prolongs the lock right after taking it, so acquiring already refreshes it.
        self::assertTrue($lock->acquire());
        $lock->refresh();

        self::assertSame('Lock storage is full', self::failureMessage($store));
    }

    public function testKeepsFirstFailure(): void
    {
        $store = new CrunzMutexAdapter(new UncreatableMutex(), 'job');
        $factory = new LockFactory($store);

        $factory->createLock('first')->acquire();
        $first = $store->failure();
        $factory->createLock('second')->acquire();

        self::assertSame($first, $store->failure());
    }
}
