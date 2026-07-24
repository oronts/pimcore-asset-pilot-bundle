<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Service\MoveOperationSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoveOperationSnapshot::class)]
final class MoveOperationSnapshotTest extends TestCase
{
    #[Test]
    public function snapshotsAreDeterministicAndIncludeTheTrigger(): void
    {
        $api = $this->operation(2, TriggerType::Api);
        $cli = $this->operation(1, TriggerType::Manual);

        $snapshots = MoveOperationSnapshot::list([$api, $cli]);

        self::assertSame(MoveOperationSnapshot::list([$cli, $api]), $snapshots);
        self::assertSame(['manual', 'api'], array_column($snapshots, 'trigger'));
    }

    private function operation(int $assetId, TriggerType $trigger): MoveOperation
    {
        return new MoveOperation(
            assetId: $assetId,
            sourcePath: '/incoming/' . $assetId . '.jpg',
            targetPath: '/organized/' . $assetId . '.jpg',
            objectId: 42,
            objectClass: 'Product',
            ruleName: 'product-assets',
            status: OperationStatus::Pending,
            triggerType: $trigger,
        );
    }
}
