<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FailureReplayService::class)]
final class FailureReplayServiceTest extends TestCase
{
    #[Test]
    public function selectionPassesEveryFilterAndExplicitLimitToAuditStore(): void
    {
        $filters = [
            'object_ids' => [42, 43],
            'since' => '2026-07-15 10:00:00',
            'rule_name' => 'images',
            'object_class' => 'Product',
        ];
        $audit = $this->createMock(AuditQueryInterface::class);
        $audit->expects(self::once())->method('getDistinctFailedObjects')->with($filters, 25)->willReturn([
            ['object_id' => 42],
        ]);

        self::assertSame([42], (new FailureReplayService($audit))->selectObjects($filters, 25));
    }

    #[Test]
    public function selectionUsesConfiguredDefaultLimit(): void
    {
        $audit = $this->createMock(AuditQueryInterface::class);
        $audit->expects(self::once())->method('getDistinctFailedObjects')->with([], 75)->willReturn([]);

        self::assertSame([], (new FailureReplayService($audit, 75))->selectObjects());
    }

    #[Test]
    public function selectionNormalizesDistinctPositiveObjectIds(): void
    {
        $audit = $this->createMock(AuditQueryInterface::class);
        $audit->method('getDistinctFailedObjects')->willReturn([
            ['object_id' => '42'],
            ['object_id' => 42],
            ['object_id' => 0],
            ['object_id' => -1],
            [],
            ['object_id' => 43],
        ]);

        self::assertSame([42, 43], (new FailureReplayService($audit))->selectObjects());
    }
}
