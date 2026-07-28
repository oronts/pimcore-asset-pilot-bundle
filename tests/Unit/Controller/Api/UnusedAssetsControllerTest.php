<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\UnusedAssetsController;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetMutationFingerprintService;
use Oronts\AssetPilotBundle\Service\QuarantineServiceInterface;
use Oronts\AssetPilotBundle\Service\StorageTrendServiceInterface;
use Oronts\AssetPilotBundle\Service\UnusedAssetFinderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(UnusedAssetsController::class)]
final class UnusedAssetsControllerTest extends TestCase
{
    #[Test]
    public function exportStreamsEveryRowFromTheAuthorizedExportIteratorNotTheBoundedPager(): void
    {
        $finder = $this->createMock(UnusedAssetFinderInterface::class);
        // The export walks iterateForExport() to true exhaustion; a large result must not be truncated by
        // the interactive pager's per-page scan ceiling (which capped the old export at ~5000 rows).
        $finder->method('iterateForExport')->willReturnCallback(
            function (): \Generator {
                yield from $this->unusedItems('a', 230);
            },
        );

        $controller = new UnusedAssetsController(
            $finder,
            $this->createMock(QuarantineServiceInterface::class),
            new NullLogger(),
            $this->createMock(StorageTrendServiceInterface::class),
            $this->createMock(ElementAuthorizationInterface::class),
            $this->createMock(ApplyPlanServiceInterface::class),
            $this->createMock(AssetMutationFingerprintService::class),
        );

        $response = $controller->export(new Request());
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $lines = array_values(array_filter(explode("\n", trim($csv)), static fn (string $line): bool => $line !== ''));
        self::assertCount(231, $lines, 'header + all 230 rows from the export iterator must stream');
        self::assertStringContainsString('/a230.png', $csv, 'the export streams every row, not just a bounded first page');
    }

    /** @return list<array<string, mixed>> */
    private function unusedItems(string $prefix, int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; ++$i) {
            $items[] = ['id' => $i, 'full_path' => '/' . $prefix . $i . '.png', 'type' => 'image', 'file_size' => 100, 'modified_at' => '2026-01-01T00:00:00+00:00'];
        }

        return $items;
    }
}
