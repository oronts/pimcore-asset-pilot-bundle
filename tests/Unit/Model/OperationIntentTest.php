<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Model;

use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperationIntent::class)]
final class OperationIntentTest extends TestCase
{
    #[Test]
    public function serializedIntentRoundTripsEveryRecoveryField(): void
    {
        $createdAt = new \DateTimeImmutable('2026-07-15T10:11:12+02:00');
        $intent = new OperationIntent(
            OperationKind::Revert,
            7,
            '/target/a.jpg',
            '/source/a.jpg',
            9,
            'Product',
            'revert:images',
            TriggerType::Manual,
            ActorContext::user(12),
            ['firstAssignment' => true],
            4,
            2,
            $createdAt,
        );

        $restored = OperationIntent::fromArray($intent->toArray());

        self::assertEquals($intent, $restored);
        $operation = $restored->toMoveOperation(OperationStatus::RecoveryRequired, 'Uncertain state.', 42);
        self::assertSame(7, $operation->assetId);
        self::assertSame(12, $operation->userId);
        self::assertSame(OperationStatus::RecoveryRequired, $operation->status);
        self::assertSame('Uncertain state.', $operation->errorMessage);
        self::assertSame(42, $operation->durationMs);
        self::assertEquals($createdAt, $operation->createdAt);
    }
}
