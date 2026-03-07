<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Strategy;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

readonly class FirstAssignmentStrategy implements ConflictStrategyInterface
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {}

    public function resolve(Asset $asset, AbstractObject $object, Rule $rule): bool
    {
        try {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM asset_pilot_audit_log WHERE asset_id = :assetId AND status = :status',
                [
                    'assetId' => $asset->getId(),
                    'status' => 'completed',
                ],
            );

            if ($count > 0) {
                $this->logger->debug('FirstAssignmentStrategy: asset {assetId} was already organized ({count} entries). Skipping.', [
                    'assetId' => $asset->getId(),
                    'count' => $count,
                ]);

                return false;
            }

            $this->logger->debug('FirstAssignmentStrategy: asset {assetId} has never been organized. Allowing move.', [
                'assetId' => $asset->getId(),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('FirstAssignmentStrategy: failed to query audit log for asset {assetId}: {error}', [
                'assetId' => $asset->getId(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    public function supports(MoveStrategy $strategy): bool
    {
        return $strategy === MoveStrategy::FirstAssignment;
    }
}
