<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Controller\Api\AuditController;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(AuditController::class)]
class AuditControllerRevertTest extends TestCase
{
    /**
     * The revert save re-triggers pimcore.asset.postUpdate -> AssetUploadListener -> organize,
     * which would move the asset straight back. The guard must mark the asset processing before
     * the save, mark it recently-moved before releasing the processing guard (so the listener is
     * never unguarded), and always release the processing guard. (P0-3, P2-21)
     */
    #[Test]
    public function saveRevertedGuardsTheSaveInTheCorrectOrder(): void
    {
        $calls = [];

        $loopGuard = $this->createMock(LoopGuard::class);
        $loopGuard->method('markAssetProcessing')
            ->willReturnCallback(function (int $id) use (&$calls): void {
                $calls[] = "markProcessing:$id";
            });
        $loopGuard->method('markAssetRecentlyMoved')
            ->willReturnCallback(function (int $id) use (&$calls): void {
                $calls[] = "markRecentlyMoved:$id";
            });
        $loopGuard->method('unmarkAssetProcessing')
            ->willReturnCallback(function (int $id) use (&$calls): void {
                $calls[] = "unmarkProcessing:$id";
            });

        $asset = $this->createMock(Asset::class);
        $asset->expects(self::once())->method('save')
            ->willReturnCallback(function () use (&$calls): Asset {
                $calls[] = 'save';
                return $this->createMock(Asset::class);
            });

        $controller = new class ($this->createMock(AuditLogger::class), new NullLogger(), $loopGuard, new EventDispatcher()) extends AuditController {
            public function exposedSaveReverted(Asset $asset, int $assetId): void
            {
                $this->saveReverted($asset, $assetId);
            }
        };

        $controller->exposedSaveReverted($asset, 42);

        self::assertSame(
            ['markProcessing:42', 'save', 'markRecentlyMoved:42', 'unmarkProcessing:42'],
            $calls,
        );
    }
}
