<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

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
            || $throwable instanceof RetryableDispatchException
            || $throwable instanceof FilesystemException
            || $throwable instanceof CacheException
            || $throwable instanceof LockAcquiringException
            || $throwable instanceof LockExpiredException
            || $throwable instanceof LockStorageException;
    }
}
