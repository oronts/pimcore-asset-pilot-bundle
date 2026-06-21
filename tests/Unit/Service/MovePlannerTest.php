<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Event\AssetMoveEvent;
use Oronts\AssetPilotBundle\Event\AssetPilotEvents;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Oronts\AssetPilotBundle\Service\MovePlanner;
use Oronts\AssetPilotBundle\Strategy\ConflictStrategyInterface;
use Oronts\AssetPilotBundle\Strategy\StrategyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(MovePlanner::class)]
class MovePlannerTest extends TestCase
{
    #[Test]
    public function skipsWhenTheStrategyRejectsTheMoveAndRecordsTheResolvedPath(): void
    {
        $planner = $this->planner(strategyAllows: false, dispatcher: new EventDispatcher());

        $plan = $planner->plan($this->asset('/source/f.jpg', locked: false), $this->object(), $this->rule(), '/target', TriggerType::Manual, dryRun: true);

        self::assertTrue($plan->isSkip());
        self::assertSame('Strategy rejected move', $plan->skipReason);
        self::assertSame('/target', $plan->targetPath);
    }

    #[Test]
    public function skipsWhenTheAssetIsAlreadyAtTheTarget(): void
    {
        $planner = $this->planner(strategyAllows: true, dispatcher: new EventDispatcher());

        $plan = $planner->plan($this->asset('/target/f.jpg', locked: false), $this->object(), $this->rule(), '/target', TriggerType::Manual, dryRun: true);

        self::assertTrue($plan->isSkip());
        self::assertSame('Asset already at target path', $plan->skipReason);
    }

    #[Test]
    public function skipsWhenAPreMoveListenerCancels(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::PRE_MOVE, static function (AssetMoveEvent $e): void {
            $e->cancel();
        });
        $planner = $this->planner(strategyAllows: true, dispatcher: $dispatcher);

        $plan = $planner->plan($this->asset('/source/f.jpg', locked: false), $this->object(), $this->rule(), '/target', TriggerType::Manual, dryRun: true);

        self::assertTrue($plan->isSkip());
        self::assertSame('Cancelled by event listener', $plan->skipReason);
    }

    #[Test]
    public function preMoveEventCarriesTheDryRunFlag(): void
    {
        $seen = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AssetPilotEvents::PRE_MOVE, static function (AssetMoveEvent $e) use (&$seen): void {
            $seen[] = $e->dryRun;
        });
        $planner = $this->planner(strategyAllows: true, dispatcher: $dispatcher);

        $planner->plan($this->asset('/source/f.jpg', locked: false), $this->object(), $this->rule(), '/target', TriggerType::Manual, dryRun: true);
        $planner->plan($this->asset('/source/f.jpg', locked: false), $this->object(), $this->rule(), '/target', TriggerType::Manual, dryRun: false);

        self::assertSame([true, false], $seen);
    }

    #[Test]
    public function skipsLockedAssets(): void
    {
        $planner = $this->planner(strategyAllows: true, dispatcher: new EventDispatcher());

        $plan = $planner->plan($this->asset('/source/f.jpg', locked: true), $this->object(), $this->rule(), '/target', TriggerType::Manual, dryRun: true);

        self::assertTrue($plan->isSkip());
        self::assertSame('Asset is locked', $plan->skipReason);
    }

    #[Test]
    public function skipsAssetsInExcludedFolders(): void
    {
        $planner = $this->planner(strategyAllows: true, dispatcher: new EventDispatcher(), excludeFolders: ['/protected']);

        $plan = $planner->plan($this->asset('/protected/f.jpg', locked: false), $this->object(), $this->rule(), '/target', TriggerType::Manual, dryRun: true);

        self::assertTrue($plan->isSkip());
        self::assertSame('Asset is in excluded folder: /protected', $plan->skipReason);
    }

    #[Test]
    public function returnsAProceedPlanWithTheFolderFilenameAndFullTargetPath(): void
    {
        $planner = $this->planner(strategyAllows: true, dispatcher: new EventDispatcher());

        $plan = $planner->plan($this->asset('/source/f.jpg', locked: false), $this->object(), $this->rule(), '/target', TriggerType::Manual, dryRun: false);

        self::assertFalse($plan->isSkip());
        self::assertNull($plan->skipReason);
        self::assertSame('/target', $plan->folderPath);
        self::assertSame('f.jpg', $plan->targetFilename);
        self::assertSame('/target/f.jpg', $plan->targetPath);
    }

    /** @param string[] $excludeFolders */
    private function planner(bool $strategyAllows, EventDispatcher $dispatcher, array $excludeFolders = []): MovePlanner
    {
        $strategy = $this->createMock(ConflictStrategyInterface::class);
        $strategy->method('resolve')->willReturn($strategyAllows);
        $resolver = $this->createMock(StrategyResolver::class);
        $resolver->method('resolve')->willReturn($strategy);

        $naming = $this->createMock(NamingStrategyInterface::class);
        $naming->method('generateName')->willReturn('f.jpg');

        return new MovePlanner($resolver, $naming, $dispatcher, $excludeFolders);
    }

    private function asset(string $path, bool $locked): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);
        $asset->method('getRealFullPath')->willReturn($path);
        $asset->method('hasProperty')->willReturn($locked);
        $asset->method('getProperty')->willReturn($locked ? '1' : null);

        return $asset;
    }

    private function object(): AbstractObject
    {
        return $this->createMock(AbstractObject::class);
    }

    private function rule(): Rule
    {
        return Rule::fromConfig('r', ['class' => 'Product', 'target_path' => '/target']);
    }
}
