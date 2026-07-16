<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command;

use Oronts\AssetPilotBundle\Command\NormalizeFilenamesCommand;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\NormalizeFilenamesService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(NormalizeFilenamesCommand::class)]
final class NormalizeFilenamesCommandTest extends TestCase
{
    #[Test]
    public function obsoleteDryRunOptionIsNotAvailable(): void
    {
        $command = $this->command(
            $this->createMock(NormalizeFilenamesService::class),
            $this->createMock(ApplyPlanServiceInterface::class),
        );

        self::assertFalse($command->getDefinition()->hasOption('dry-run'));
    }

    #[Test]
    public function applyRequiresAPlanTokenBeforeSelectingAssets(): void
    {
        $normalizer = $this->createMock(NormalizeFilenamesService::class);
        $normalizer->expects(self::never())->method('findCandidates');
        $normalizer->expects(self::never())->method('normalize');
        $tester = new CommandTester($this->command($normalizer, $this->createMock(ApplyPlanServiceInterface::class)));

        $status = $tester->execute(['--folder' => '/uploads', '--apply' => true]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('--plan-token', $tester->getDisplay());
    }

    #[Test]
    public function planTokenIsRejectedDuringPreview(): void
    {
        $normalizer = $this->createMock(NormalizeFilenamesService::class);
        $normalizer->expects(self::never())->method('normalize');
        $tester = new CommandTester($this->command($normalizer, $this->createMock(ApplyPlanServiceInterface::class)));

        $status = $tester->execute(['--by-ids' => '7', '--plan-token' => 'token']);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('only valid together with --apply', $tester->getDisplay());
    }

    #[Test]
    public function malformedOrUnboundedLimitsAreRejectedBeforeSelection(): void
    {
        foreach (['invalid', '0', '1001', '1.5'] as $limit) {
            $normalizer = $this->createMock(NormalizeFilenamesService::class);
            $normalizer->expects(self::never())->method('findCandidates');
            $normalizer->expects(self::never())->method('normalize');
            $tester = new CommandTester($this->command($normalizer, $this->createMock(ApplyPlanServiceInterface::class)));

            self::assertSame(Command::INVALID, $tester->execute(['--folder' => '/uploads', '--limit' => $limit]));
            self::assertStringContainsString('between 1 and 1000', $tester->getDisplay());
        }
    }

    #[Test]
    public function previewIssuesAnExactSortedSystemPlan(): void
    {
        $preview = $this->previewResult();
        $normalizer = $this->createMock(NormalizeFilenamesService::class);
        $normalizer->expects(self::once())->method('normalize')->with([2, 7], true)->willReturn($preview);
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('issue')->with(self::callback(static function (ApplyPlan $plan): bool {
            self::assertSame('normalize-filenames', $plan->kind);
            self::assertEquals(ActorContext::system(), $plan->actor);
            self::assertSame([
                'assetIds' => [2, 7],
                'selector' => ['assetIds' => [2, 7], 'mode' => 'asset_ids'],
            ], $plan->request);
            self::assertSame(['asset:2', 'asset:7'], array_column($plan->targets, 'id'));
            foreach ($plan->targets as $target) {
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $target->fingerprint);
            }

            return true;
        }))->willReturn('signed-plan');
        $tester = new CommandTester($this->command($normalizer, $plans));

        $status = $tester->execute(['--by-ids' => '7,2']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Plan token:', $tester->getDisplay());
        self::assertStringContainsString('signed-plan', $tester->getDisplay());
    }

    #[Test]
    public function applyClaimsTheCurrentDescriptorBeforeTheFirstMutation(): void
    {
        $events = [];
        $preview = $this->previewResult();
        $normalizer = $this->createMock(NormalizeFilenamesService::class);
        $normalizer->expects(self::exactly(2))->method('normalize')->willReturnCallback(
            static function (array $assetIds, bool $dryRun) use (&$events, $preview): array {
                self::assertSame([2, 7], $assetIds);
                $events[] = $dryRun ? 'preview' : 'mutate';

                return $dryRun
                    ? $preview
                    : ['renamed' => 2, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'changes' => $preview['changes']];
            },
        );
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->with('signed-plan', self::isInstanceOf(ApplyPlan::class))
            ->willReturnCallback(static function () use (&$events): ApplyPlanStatus {
                $events[] = 'claim';

                return ApplyPlanStatus::Claimed;
            });
        $tester = new CommandTester($this->command($normalizer, $plans));

        $status = $tester->execute([
            '--by-ids' => '7,2',
            '--apply' => true,
            '--plan-token' => 'signed-plan',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['preview', 'claim', 'mutate'], $events);
    }

    #[Test]
    public function staleDescriptorPlanIsRejectedWithoutMutation(): void
    {
        $normalizer = $this->createMock(NormalizeFilenamesService::class);
        $normalizer->expects(self::once())->method('normalize')->with([2, 7], true)->willReturn($this->previewResult());
        $plans = $this->createMock(ApplyPlanServiceInterface::class);
        $plans->expects(self::once())->method('claim')->willReturn(ApplyPlanStatus::Stale);
        $tester = new CommandTester($this->command($normalizer, $plans));

        $status = $tester->execute([
            '--by-ids' => '7,2',
            '--apply' => true,
            '--plan-token' => 'stale-plan',
        ]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Preview again', $tester->getDisplay());
    }

    private function command(
        NormalizeFilenamesService $normalizer,
        ApplyPlanServiceInterface $plans,
    ): NormalizeFilenamesCommand {
        return new NormalizeFilenamesCommand($normalizer, $plans);
    }

    /**
     * @return array{renamed: int, skipped: int, failed: int, errors: array<int, string>, changes: list<array{id: int, from: string, to: string}>}
     */
    private function previewResult(): array
    {
        return [
            'renamed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'changes' => [
                ['id' => 2, 'from' => 'bad two.jpg', 'to' => 'bad-two.jpg'],
                ['id' => 7, 'from' => 'bad seven.jpg', 'to' => 'bad-seven.jpg'],
            ],
        ];
    }
}
