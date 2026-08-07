<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\NoActiveTransaction;
use Doctrine\DBAL\Exception\RetryableException;
use League\Flysystem\FilesystemException;
use Oronts\AssetPilotBundle\Exception\RetryableDispatchException;
use Psr\Cache\CacheException;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockExpiredException;
use Symfony\Component\Lock\Exception\LockStorageException;

class RetryableInfrastructureFailure
{
    public static function matches(\Throwable $throwable): bool
    {
        return $throwable instanceof RetryableException
            || $throwable instanceof ConnectionLost
            // A lost connection inside Connection::transactional() closes the connection (nesting -> 0), so the
            // finally-block rollBack() throws NoActiveTransaction, masking the ConnectionLost; this bundle never
            // manages transactions by hand, so this can only mean the connection dropped: retry, do not fail.
            || $throwable instanceof NoActiveTransaction
            || $throwable instanceof RetryableDispatchException
            || $throwable instanceof FilesystemException
            || $throwable instanceof CacheException
            || $throwable instanceof LockAcquiringException
            || $throwable instanceof LockExpiredException
            || $throwable instanceof LockStorageException;
    }
}
