<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Strategy;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;

readonly class AlwaysMoveStrategy implements SideEffectFreeConflictStrategyInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function resolve(Asset $asset, AbstractObject $object, Rule $rule): bool
    {
        $this->logger->debug('AlwaysMoveStrategy: allowing move for asset {assetId} via rule "{rule}".', [
            'assetId' => $asset->getId(),
            'rule' => $rule->name,
        ]);

        return true;
    }

    public function supports(MoveStrategy $strategy): bool
    {
        return $strategy === MoveStrategy::Always;
    }
}
