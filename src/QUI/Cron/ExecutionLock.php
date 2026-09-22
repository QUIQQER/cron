<?php

namespace QUI\Cron;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Cron callbacks may block indefinitely: use a process lock that cannot expire
 * while its owner is still running. Do not unlink its files, even after release.
 */
final class ExecutionLock
{
    public static function create(): LockInterface
    {
        $Factory = new LockFactory(new FlockStore(VAR_DIR . 'locks/'));

        return $Factory->createLock('quiqqer-cron-' . hash('sha256', realpath(CMS_DIR) ?: CMS_DIR), null);
    }
}
