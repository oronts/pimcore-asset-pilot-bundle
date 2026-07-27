<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Oronts\AssetPilotBundle\Installer;

class DbalApplyPlanClaimStore implements ApplyPlanClaimStoreInterface
{
    public const int DEFAULT_CLEANUP_BATCH_SIZE = 100;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $cleanupBatchSize = self::DEFAULT_CLEANUP_BATCH_SIZE,
    ) {
        if ($cleanupBatchSize <= 0) {
            throw new \InvalidArgumentException('The apply plan claim cleanup batch size must be positive.');
        }
    }

    public function claim(
        string $claimId,
        \DateTimeImmutable $claimedAt,
        \DateTimeImmutable $expiresAt,
    ): bool {
        if (preg_match('/^[a-f0-9]{64}$/D', $claimId) !== 1) {
            throw new \InvalidArgumentException('An apply plan claim ID must be a SHA-256 hash.');
        }
        if ($expiresAt <= $claimedAt) {
            throw new \InvalidArgumentException('An apply plan claim must expire after it is created.');
        }

        $claimed = $this->format($claimedAt);
        $this->purgeExpired($claimed);

        try {
            $this->connection->insert(Installer::TABLE_APPLY_PLAN_CLAIM, [
                'id' => $claimId,
                'claimed_at' => $claimed,
                'expires_at' => $this->format($expiresAt),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    protected function purgeExpired(string $claimedAt): int
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(Installer::TABLE_APPLY_PLAN_CLAIM)
            ->where('expires_at <= :claimedAt')
            ->setParameter('claimedAt', $claimedAt)
            ->orderBy('expires_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($this->cleanupBatchSize)
            ->fetchFirstColumn();
        if ($ids === []) {
            return 0;
        }

        return $this->connection->executeStatement(
            'DELETE FROM ' . Installer::TABLE_APPLY_PLAN_CLAIM . ' WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::STRING],
        );
    }

    protected function format(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
