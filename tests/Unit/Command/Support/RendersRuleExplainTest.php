<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command\Support;

use Oronts\AssetPilotBundle\Command\Support\RendersRuleExplain;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversTrait(RendersRuleExplain::class)]
class RendersRuleExplainTest extends TestCase
{
    public function testEmptyEvaluationsDefaultRendersDecisionLine(): void
    {
        $out = new BufferedOutput();
        $this->subject()->run($this->io($out), $this->object(), $this->asset(), false);
        $display = $out->fetch();

        self::assertStringContainsString('No rules matched this asset.', $display);
        self::assertStringNotContainsString('No rules to evaluate.', $display);
    }

    public function testEmptyEvaluationsAnnounceShortCircuits(): void
    {
        $out = new BufferedOutput();
        $this->subject()->run($this->io($out), $this->object(), $this->asset(), true);
        $display = $out->fetch();

        self::assertStringContainsString('No rules to evaluate.', $display);
        self::assertStringNotContainsString('No rules matched this asset.', $display);
    }

    private function subject(): object
    {
        $ruleEngine = $this->createMock(RuleEngineInterface::class);
        $ruleEngine->method('explain')->willReturn(['evaluations' => [], 'matches' => []]);

        return new class ($ruleEngine, $this->createMock(NamingStrategyInterface::class)) {
            use RendersRuleExplain;

            public function __construct(
                private readonly RuleEngineInterface $ruleEngine,
                private readonly NamingStrategyInterface $namingStrategy,
            ) {}

            public function run(SymfonyStyle $io, AbstractObject $object, Asset $asset, bool $announce): void
            {
                $this->renderRuleExplain($io, $object, $asset, null, null, null, $announce);
            }
        };
    }

    private function object(): AbstractObject
    {
        $object = $this->createMock(AbstractObject::class);
        $object->method('getId')->willReturn(1);

        return $object;
    }

    private function asset(): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(1);
        $asset->method('getRealFullPath')->willReturn('/uploads/photo.jpg');

        return $asset;
    }

    private function io(BufferedOutput $out): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $out);
    }
}
