<?php

namespace CronRunner\Mutex;

use CronRunner\Exceptions\MutexException;
use CronRunner\Interfaces\Mutex;

/**
 * Mutex, which keeps locks as files in a directory shared by the processes.
 */
class FileMutex implements Mutex
{
    /**
     * Directory where mutex files are stored.
     *
     * @var string
     */
    private string $directory;

    /**
     * Default time to live of a mutex in seconds.
     *
     * @var float
     */
    private float $ttl;

    /**
     * @param  string|null  $directory  Directory for mutex files, a temp subdirectory by default
     * @param  float  $ttl  Default time to live of a mutex in seconds
     */
    public function __construct(?string $directory = null, float $ttl = 3600)
    {
        $this->directory = rtrim($directory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cron-runner-mutex', DIRECTORY_SEPARATOR);
        $this->ttl = $ttl;

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0777, true);
        }
    }

    public function create(string $key, ?float $ttl = null): ?string
    {
        $path = $this->path($key);
        $ttl ??= $this->ttl;

        return $this->guarded($path, function () use ($path, $ttl): ?string {
            // Expired, or left empty by a process that crashed while writing it.
            if (file_exists($path) && !$this->isHeld($path)) {
                @unlink($path);
            }

            // Mode "x" fails if the file already exists, which makes the creation atomic.
            $handle = @fopen($path, 'x');

            if ($handle === false) {
                return null;
            }

            $token = bin2hex(random_bytes(16));
            $content = $this->format($token, microtime(true) + $ttl);
            $written = fwrite($handle, $content);
            fclose($handle);

            // E.g. a full disk; the incomplete lock file is removed, as nobody got its token.
            if ($written !== strlen($content)) {
                @unlink($path);
                throw new MutexException("Can't write mutex file {$path}");
            }

            return $token;
        });
    }

    public function exists(string $key, ?string $token = null): bool
    {
        $path = $this->path($key);

        return $this->guarded($path, fn (): bool => $this->isHeld($path, $token), LOCK_SH);
    }

    /**
     * If the mutex file can't be deleted, the lock stays until it expires.
     */
    public function remove(string $key, string $token): void
    {
        $path = $this->path($key);

        $this->guarded($path, function () use ($token, $path): void {
            if ($this->isHeld($path, $token)) {
                @unlink($path);
            }
        });
    }

    /**
     * Returns false also if the mutex file can't be written.
     */
    public function prolongExpiration(string $key, string $token, float $ttl): bool
    {
        $path = $this->path($key);

        return $this->guarded($path, function () use ($token, $ttl, $path): bool {
            if (!$this->isHeld($path, $token)) {
                return false;
            }

            $content = $this->format($token, microtime(true) + $ttl);

            return file_put_contents($path, $content) === strlen($content);
        });
    }

    /**
     * Whether the mutex file holds an unexpired lock, owned by the token if one is given. Call only while guarded.
     * Every write happens under the guard, so a file without readable content was left by a crashed process.
     */
    private function isHeld(string $path, ?string $token = null): bool
    {
        $lock = $this->read($path);

        if ($lock === null || $lock['expiresAt'] < microtime(true)) {
            return false;
        }

        return $token === null || hash_equals($lock['token'], $token);
    }

    /**
     * Path of the mutex file for the key.
     */
    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . sha1($key) . '.lock';
    }

    /**
     * Run the callback while holding a lock on the guard file, so checking and changing
     * the mutex file can't interleave with another process.
     * Guard file is separate and never deleted, because the mutex file itself gets deleted and recreated.
     *
     * @param  LOCK_EX|LOCK_SH  $operation  LOCK_EX for changes, LOCK_SH for reading only
     */
    private function guarded(string $path, callable $callback, int $operation = LOCK_EX): mixed
    {
        $guard = @fopen($path . '.guard', 'c');

        if ($guard === false) {
            throw new MutexException("Can't open mutex guard file {$path}.guard");
        }

        try {
            // Without the lock the guard would silently do nothing, and processes could race again.
            if (!$this->lockGuard($guard, $operation)) {
                throw new MutexException("Can't lock mutex guard file {$path}.guard, its storage may not support file locks");
            }

            return $callback();
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    /**
     * Separate, so tests can simulate storage without file lock support, where flock() fails.
     *
     * @param  resource  $guard
     * @param  LOCK_EX|LOCK_SH  $operation
     */
    protected function lockGuard($guard, int $operation): bool
    {
        return flock($guard, $operation);
    }

    /**
     * Content of the mutex file.
     */
    private function format(string $token, float $expiresAt): string
    {
        return $token . "\n" . $expiresAt;
    }

    /**
     * @return array{token: string, expiresAt: float}|null
     */
    private function read(string $path): ?array
    {
        $content = @file_get_contents($path);

        if ($content === false || !str_contains($content, "\n")) {
            return null;
        }

        [$token, $expiresAt] = explode("\n", $content, 2);

        return ['token' => $token, 'expiresAt' => (float) $expiresAt];
    }
}
