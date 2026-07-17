<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Support;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\DependencyUsageVerdict;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Security\ActorContextProvider;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\AssetDeletionFenceInterface;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\ContentUsageScanner;
use Oronts\AssetPilotBundle\Service\DependencyUsageVerifierInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\LoopGuardedAssetSaver;
use Oronts\AssetPilotBundle\Service\Query\AssetWorkspaceQueryScope;
use Oronts\AssetPilotBundle\Service\ReviewedAssetLockCoordinator;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

trait MutationSafetyDependencies
{
    /**
     * @return array{
     *     ContentUsageScanner,
     *     LoopGuard,
     *     ReviewedAssetLockCoordinator,
     *     ElementAuthorization,
     *     DependencyUsageVerifierInterface,
     *     AssetWorkspaceQueryScope,
     *     AssetMutationFingerprintService,
     *     LoopGuardedAssetSaver,
     *     AssetDeletionFenceInterface
     * }
     */
    private function mutationSafetyDependencies(
        Connection $connection,
        ?ContentUsageScanner $contentScanner = null,
        ?LoopGuard $loopGuard = null,
        ?ElementAuthorization $authorization = null,
        ?DependencyUsageVerifierInterface $dependencyVerifier = null,
        ?AssetWorkspaceQueryScope $workspaceScope = null,
        ?AssetMutationFingerprintService $fingerprints = null,
        bool $contentEvidence = true,
        ?AssetDeletionFenceInterface $deletionFence = null,
    ): array {
        $contentScanner ??= $this->createMock(ContentUsageScanner::class);
        $contentScanner->method('canVerify')->willReturn($contentEvidence);
        $loopGuard ??= new LoopGuard(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        if ($authorization === null) {
            $authorization = $this->createMock(ElementAuthorization::class);
            $authorization->method('currentActor')->willReturn(ActorContext::system());
            $authorization->method('isAllowed')->willReturnCallback(
                static fn (AbstractElement $element, string $permission): bool => $element->isAllowed($permission),
            );
        }
        if ($dependencyVerifier === null) {
            $dependencyVerifier = $this->createMock(DependencyUsageVerifierInterface::class);
            $dependencyVerifier->method('verdict')->willReturn(DependencyUsageVerdict::Safe);
        }
        $workspaceScope ??= new AssetWorkspaceQueryScope(
            $connection,
            $authorization,
            $this->createMock(ActorContextProvider::class),
        );
        $fingerprints ??= $this->createMock(AssetMutationFingerprintService::class);
        if ($deletionFence === null) {
            $deletionFence = $this->createMock(AssetDeletionFenceInterface::class);
            $deletionFence->method('acquire')->willReturn('test-fence-token');
        }

        return [
            $contentScanner,
            $loopGuard,
            new ReviewedAssetLockCoordinator($loopGuard),
            $authorization,
            $dependencyVerifier,
            $workspaceScope,
            $fingerprints,
            new LoopGuardedAssetSaver($loopGuard),
            $deletionFence,
        ];
    }
}
