<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Service\Query;

use Doctrine\DBAL\Connection;

/**
 * Whether a Pimcore asset id is the target of any live dependency edge — the shared reference count that
 * gates "is this still referenced" across the unused-asset and empty-folder delete guards, so the two
 * cannot drift on how a reference is counted.
 *
 * @internal Not a documented extension seam; relocate-safe.
 */
final class AssetDependencyCount
{
    public static function isTargetReferenced(Connection $connection, int $targetId): bool
    {
        $count = (int) $connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(PimcoreSchema::TABLE_DEPENDENCIES)
            ->where('targetid = :id')
            ->andWhere('targettype = :type')
            ->setParameter('id', $targetId)
            ->setParameter('type', PimcoreSchema::ELEMENT_TYPE_ASSET)
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }
}
