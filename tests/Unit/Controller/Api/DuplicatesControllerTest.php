<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller\Api;

use Oronts\AssetPilotBundle\Controller\Api\DuplicatesController;
use Oronts\AssetPilotBundle\Model\DuplicateGroup;
use Oronts\AssetPilotBundle\Security\ElementAuthorizationInterface;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\AssetSearchServiceInterface;
use Oronts\AssetPilotBundle\Service\DuplicateDetectionServiceInterface;
use Oronts\AssetPilotBundle\Service\DuplicateMergeServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(DuplicatesController::class)]
final class DuplicatesControllerTest extends TestCase
{
    #[Test]
    public function exportStreamsEveryGroupFromTheDuplicateExportIterator(): void
    {
        $duplicates = $this->createMock(DuplicateDetectionServiceInterface::class);
        $groups = $this->duplicateGroups('c-', 430);
        $duplicates->method('iterateForExport')->willReturnCallback(
            static function () use ($groups): \Generator {
                yield from $groups;
            },
        );

        $controller = new DuplicatesController(
            $duplicates,
            $this->createMock(DuplicateMergeServiceInterface::class),
            $this->createMock(AssetSearchServiceInterface::class),
            $this->createMock(ApplyPlanServiceInterface::class),
            $this->createMock(ElementAuthorizationInterface::class),
            $this->createMock(UrlGeneratorInterface::class),
            new NullLogger(),
        );

        $response = $controller->export(new Request());
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $lines = array_values(array_filter(explode("\n", trim($csv)), static fn (string $line): bool => $line !== ''));
        self::assertCount(431, $lines, 'header + all 430 groups from the export iterator must stream');
        self::assertStringContainsString('c-430', $csv, 'the export streams every group from iterateForExport');
    }

    /** @return list<DuplicateGroup> */
    private function duplicateGroups(string $prefix, int $count): array
    {
        $groups = [];
        for ($i = 1; $i <= $count; ++$i) {
            $groups[] = new DuplicateGroup($prefix . $i, 1024, 3, [$i, $i + 1000, $i + 2000]);
        }

        return $groups;
    }
}
