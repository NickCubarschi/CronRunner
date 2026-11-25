<?php

namespace CronRunner\Tests\Integration\Mutex;

use CronRunner\Exceptions\CronRunnerException;
use CronRunner\Exceptions\MutexException;
use CronRunner\Mutex\FileMutex;
use CronRunner\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FileMutexTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = $this->temporaryDirectory();
    }

    public function testCreatesDirectory(): void
    {
        $directory = $this->directory . '/nested/mutexes';

        (new FileMutex($directory))->create('job');

        self::assertDirectoryExists($directory);
    }

    public function testCreateReturnsTokenOnlyWhileFree(): void
    {
        $mutex = new FileMutex($this->directory);

        $token = $mutex->create('job');

        self::assertIsString($token);
        self::assertNotSame('', $token);
        self::assertNull($mutex->create('job'));
    }

    public function testTokensAreUnique(): void
    {
        $mutex = new FileMutex($this->directory);

        $first = $mutex->create('job');
        self::assertNotNull($first);
        $mutex->remove('job', $first);

        self::assertNotSame($first, $mutex->create('job'));
    }

    public function testExistsWithoutTokenForAnyHolder(): void
    {
        $mutex = new FileMutex($this->directory);

        self::assertFalse($mutex->exists('job'));

        $mutex->create('job');

        self::assertTrue($mutex->exists('job'));
    }

    public function testExistsWithTokenOnlyForOwner(): void
    {
        $mutex = new FileMutex($this->directory);
        $token = $mutex->create('job');

        self::assertTrue($mutex->exists('job', $token));
        self::assertFalse($mutex->exists('job', 'someone-else'));
    }

    public function testRemoveWithOwnerToken(): void
    {
        $mutex = new FileMutex($this->directory);
        $token = $mutex->create('job');
        self::assertNotNull($token);

        $mutex->remove('job', $token);

        self::assertFalse($mutex->exists('job'));
        self::assertNotNull($mutex->create('job'));
    }

    public function testRemoveWithWrongTokenKeepsLock(): void
    {
        $mutex = new FileMutex($this->directory);
        $mutex->create('job');

        $mutex->remove('job', 'someone-else');

        self::assertTrue($mutex->exists('job'));
    }

    public function testRemoveOfMissingLockDoesNothing(): void
    {
        $mutex = new FileMutex($this->directory);

        $mutex->remove('job', 'token');

        self::assertFalse($mutex->exists('job'));
    }

    public function testKeysAreIndependent(): void
    {
        $mutex = new FileMutex($this->directory);

        self::assertNotNull($mutex->create('first'));
        self::assertNotNull($mutex->create('second'));
    }

    public function testInstancesShareLocksThroughDirectory(): void
    {
        (new FileMutex($this->directory))->create('job');

        self::assertTrue((new FileMutex($this->directory))->exists('job'));
        self::assertNull((new FileMutex($this->directory))->create('job'));
    }

    public function testExpiredLockIsFreeAndCanBeTakenOver(): void
    {
        $mutex = new FileMutex($this->directory, 0.2);
        $mutex->create('job');

        usleep(300_000);

        self::assertFalse($mutex->exists('job'));
        self::assertNotNull($mutex->create('job'));
    }

    public function testTtlOfCreateOverridesDefault(): void
    {
        $mutex = new FileMutex($this->directory, 3600);
        $mutex->create('short', 0.2);
        $mutex->create('default');

        usleep(300_000);

        self::assertFalse($mutex->exists('short'));
        self::assertTrue($mutex->exists('default'));
    }

    public function testOwnerProlongsExpiration(): void
    {
        $mutex = new FileMutex($this->directory, 0.2);
        $token = $mutex->create('job');
        self::assertNotNull($token);

        self::assertTrue($mutex->prolongExpiration('job', $token, 60));

        usleep(300_000);

        self::assertTrue($mutex->exists('job', $token));
    }

    public function testOtherTokenCanNotProlongExpiration(): void
    {
        $mutex = new FileMutex($this->directory, 0.2);
        $mutex->create('job');

        self::assertFalse($mutex->prolongExpiration('job', 'someone-else', 60));

        usleep(300_000);

        self::assertFalse($mutex->exists('job'));
    }

    public function testMissingLockCanNotBeProlonged(): void
    {
        self::assertFalse((new FileMutex($this->directory))->prolongExpiration('job', 'token', 60));
    }

    public function testPreviousOwnerCanNotRemoveLockTakenOverAfterExpiration(): void
    {
        $mutex = new FileMutex($this->directory, 0.2);
        $oldToken = $mutex->create('job');
        self::assertNotNull($oldToken);
        usleep(300_000);
        $newToken = $mutex->create('job');

        $mutex->remove('job', $oldToken);

        self::assertTrue($mutex->exists('job', $newToken));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenLockContents(): array
    {
        return [
            // A process that crashed between creating the file and writing to it, or a full disk.
            'empty' => [''],
            'without expiration' => ['token'],
        ];
    }

    #[DataProvider('brokenLockContents')]
    public function testBrokenLockFileDoesNotBlockForever(string $content): void
    {
        $mutex = new FileMutex($this->directory);
        file_put_contents($this->directory . '/' . sha1('job') . '.lock', $content);

        self::assertFalse($mutex->exists('job'));
        self::assertNotNull($mutex->create('job'));
        self::assertTrue($mutex->exists('job'));
    }

    /**
     * @return array<string, array{\Closure(FileMutex): mixed}>
     */
    public static function operations(): array
    {
        return [
            'create' => [static fn (FileMutex $mutex) => $mutex->create('job')],
            'exists' => [static fn (FileMutex $mutex) => $mutex->exists('job')],
            'remove' => [static fn (FileMutex $mutex) => $mutex->remove('job', 'token')],
            'prolong' => [static fn (FileMutex $mutex) => $mutex->prolongExpiration('job', 'token', 60)],
        ];
    }

    /**
     * @param  \Closure(FileMutex): mixed  $operation
     */
    #[DataProvider('operations')]
    public function testUnusableStorageThrowsMutexException(\Closure $operation): void
    {
        // A regular file where the directory should be, so no lock file can be created in it.
        $file = $this->directory . '/not-a-directory';
        touch($file);

        try {
            $operation(new FileMutex($file));
            self::fail('MutexException was expected');
        } catch (MutexException $e) {
            self::assertInstanceOf(CronRunnerException::class, $e);
            self::assertStringContainsString("Can't open mutex guard file", $e->getMessage());
        }
    }

    /**
     * @param  \Closure(FileMutex): mixed  $operation
     */
    #[DataProvider('operations')]
    public function testStorageWithoutFileLocksThrowsMutexException(\Closure $operation): void
    {
        // Storage without flock() support, like some network file systems.
        $mutex = new class ($this->directory) extends FileMutex {
            protected function lockGuard($guard, int $operation): bool
            {
                return false;
            }
        };

        try {
            $operation($mutex);
            self::fail('MutexException was expected');
        } catch (MutexException $e) {
            self::assertStringContainsString("Can't lock mutex guard file", $e->getMessage());
        }

        // Nothing was done without the guard.
        self::assertFileDoesNotExist($this->directory . '/' . sha1('job') . '.lock');
    }

    public function testOnlyOneOfConcurrentProcessesTakesOverExpiredLock(): void
    {
        (new FileMutex($this->directory, 0.1))->create('race');
        usleep(200_000);

        $processes = [];
        for ($i = 0; $i < 20; $i++) {
            $processes[] = self::startPhp(['tests/Fixtures/take-lock.php', $this->directory, 'race']);
        }

        $winners = 0;
        foreach ($processes as $process) {
            $result = self::waitFor($process);
            self::assertSame(0, $result['exitCode'], $result['output']);
            $winners += $result['output'] === 'won' ? 1 : 0;
        }

        self::assertSame(1, $winners);
    }
}
