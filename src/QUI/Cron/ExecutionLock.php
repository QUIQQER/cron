<?php

namespace QUI\Cron;

use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Cron callbacks may block indefinitely: use a process lock that cannot expire
 * while its owner is still running. Do not unlink its files, even after release.
 */
final class ExecutionLock implements LockInterface
{
    /** @var resource|null */
    private $workerHandle = null;

    private string $workerToken = '';

    public function __construct(
        private LockInterface $CycleLock,
        private string $workerLockPath
    ) {
    }

    public static function create(): self
    {
        $Factory = new LockFactory(new FlockStore(VAR_DIR . 'locks/'));
        $key = 'quiqqer-cron-' . hash('sha256', realpath(CMS_DIR) ?: CMS_DIR);

        return new self(
            $Factory->createLock($key, null),
            VAR_DIR . 'locks/' . $key . '.worker.lock'
        );
    }

    public function acquire(bool $blocking = false): bool
    {
        if ($this->workerHandle !== null) {
            return true;
        }

        if (!$this->CycleLock->acquire($blocking)) {
            return false;
        }

        try {
            // Like FlockStore, allow different CLI/web users to share the installation lock.
            $handle = @fopen($this->workerLockPath, 'x+');

            if ($handle !== false) {
                chmod($this->workerLockPath, 0666);
            } else {
                $handle = @fopen($this->workerLockPath, 'r+') ?: @fopen($this->workerLockPath, 'r');
            }

            if ($handle === false) {
                throw new RuntimeException('Cannot open cron worker lock.');
            }

            if (!flock($handle, LOCK_EX | ($blocking ? 0 : LOCK_NB))) {
                fclose($handle);
                $this->CycleLock->release();

                return false;
            }

            $this->workerHandle = $handle;
            $this->workerToken = bin2hex(random_bytes(32));

            if (
                !ftruncate($handle, 0)
                || fwrite($handle, $this->workerToken) !== 64
                || !fflush($handle)
                || !flock($handle, LOCK_SH)
            ) {
                throw new RuntimeException('Cannot initialize cron worker lock.');
            }

            return true;
        } catch (\Throwable $Error) {
            $this->release();

            throw $Error;
        }
    }

    /** @return array{path: string, token: string} */
    public function getWorkerContext(): array
    {
        if ($this->workerHandle === null) {
            throw new RuntimeException('Cron execution lock is not acquired.');
        }

        return [
            'path' => $this->workerLockPath,
            'token' => $this->workerToken
        ];
    }

    /**
     * Join only the cycle that launched this worker. An exclusive contender rotates the token,
     * so a delayed child can never join a successor's cycle after its supervisor died.
     *
     * @return resource
     */
    public static function holdWorkerLock(string $path, string $token)
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Cannot open cron worker lock.');
        }

        if (
            !flock($handle, LOCK_SH | LOCK_NB)
            || $token === ''
            || !hash_equals($token, (string)fread($handle, 65))
        ) {
            fclose($handle);

            throw new RuntimeException('Cron worker no longer belongs to the active cycle.');
        }

        return $handle;
    }

    public function release(): void
    {
        if ($this->workerHandle !== null) {
            // A worker has its own shared handle and keeps the guard locked if the supervisor dies.
            fclose($this->workerHandle);
            $this->workerHandle = null;
            $this->workerToken = '';
        }

        $this->CycleLock->release();
    }

    public function refresh(?float $ttl = null): void
    {
        $this->CycleLock->refresh($ttl);
    }

    public function isAcquired(): bool
    {
        return $this->workerHandle !== null && $this->CycleLock->isAcquired();
    }

    public function isExpired(): bool
    {
        return $this->CycleLock->isExpired();
    }

    public function getRemainingLifetime(): ?float
    {
        return $this->CycleLock->getRemainingLifetime();
    }

    public function __destruct()
    {
        $this->release();
    }
}
