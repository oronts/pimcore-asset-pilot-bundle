<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Action;

use Oronts\AssetPilotBundle\Action\ConvertFormatAction;
use Oronts\AssetPilotBundle\Action\RuleActionDeliveryContextInterface;
use Oronts\AssetPilotBundle\Service\Conversion\AssetConverterInterface;
use Oronts\AssetPilotBundle\Service\Conversion\AssetConverterResolverInterface;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use Oronts\AssetPilotBundle\Service\LoopGuardedAssetSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(ConvertFormatAction::class)]
final class ConvertFormatActionTest extends TestCase
{
    #[Test]
    public function prepareNormalizesTheFormatAndFoldsQualityIntoOptions(): void
    {
        $payload = $this->action()->prepare($this->image('a.png'), $this->createMock(AbstractObject::class), ['format' => 'JPG', 'quality' => 70]);

        self::assertSame('jpeg', $payload['format']);
        self::assertSame(['quality' => 70], $payload['options']);
    }

    #[Test]
    public function prepareRejectsAMissingFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->action()->prepare($this->image('a.png'), $this->createMock(AbstractObject::class), []);
    }

    #[Test]
    public function validateConfigReportsMissingFormatAndBadQuality(): void
    {
        $action = $this->action();

        self::assertSame([], $action->validateConfig(['format' => 'webp']));
        self::assertContains('requires a non-empty "format"', $action->validateConfig([]));
        self::assertContains('quality must be an integer between 1 and 100', $action->validateConfig(['format' => 'png', 'quality' => 500]));
    }

    #[Test]
    public function convertsAnImageThroughTheResolvedConverterAndRetargetsTheFilename(): void
    {
        $asset = $this->image('photo.png');
        $converter = $this->createMock(AssetConverterInterface::class);
        $converter->method('convert')->with($asset, 'jpeg', self::anything())->willReturn('jpeg-bytes');
        $resolver = $this->createMock(AssetConverterResolverInterface::class);
        $resolver->method('resolve')->with('jpeg')->willReturn($converter);

        $captured = null;
        $saver = $this->createMock(LoopGuardedAssetSaver::class);
        $saver->expects(self::once())->method('save')->willReturnCallback(
            function (Asset $target, ?callable $mutate) use (&$captured): void {
                $captured = $mutate;
            },
        );

        $this->action($resolver, $saver)->applyPrepared($asset, ['format' => 'jpeg'], $this->delivery());

        self::assertIsCallable($captured);
        $mutated = $this->createMock(Asset\Image::class);
        $mutated->expects(self::once())->method('setData')->with('jpeg-bytes');
        $mutated->expects(self::once())->method('setFilename')->with('photo.jpg');
        $captured($mutated);
    }

    #[Test]
    public function degradesGracefullyWhenNoConverterIsAvailable(): void
    {
        $resolver = $this->createMock(AssetConverterResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);
        $saver = $this->createMock(LoopGuardedAssetSaver::class);
        $saver->expects(self::never())->method('save');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $this->action($resolver, $saver, $logger)->applyPrepared($this->image('a.png'), ['format' => 'avif'], $this->delivery());
    }

    #[Test]
    public function skipsANonImageAssetWithoutResolvingAConverter(): void
    {
        $resolver = $this->createMock(AssetConverterResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        $this->action($resolver)->applyPrepared($this->createMock(Asset\Document::class), ['format' => 'jpeg'], $this->delivery());
    }

    #[Test]
    public function skipsAnAssetAlreadyInTheTargetFormat(): void
    {
        $resolver = $this->createMock(AssetConverterResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');

        $this->action($resolver)->applyPrepared($this->image('already.jpg'), ['format' => 'jpeg'], $this->delivery());
    }

    #[Test]
    public function leavesTheAssetUnchangedWhenTheConverterCannotEncode(): void
    {
        $converter = $this->createMock(AssetConverterInterface::class);
        $converter->method('convert')->willReturn(null);
        $resolver = $this->createMock(AssetConverterResolverInterface::class);
        $resolver->method('resolve')->willReturn($converter);
        $saver = $this->createMock(LoopGuardedAssetSaver::class);
        $saver->expects(self::never())->method('save');

        $this->action($resolver, $saver)->applyPrepared($this->image('a.png'), ['format' => 'jpeg'], $this->delivery());
    }

    private function action(
        ?AssetConverterResolverInterface $resolver = null,
        ?LoopGuardedAssetSaver $saver = null,
        ?LoggerInterface $logger = null,
    ): ConvertFormatAction {
        return new ConvertFormatAction(
            $resolver ?? $this->createMock(AssetConverterResolverInterface::class),
            $saver ?? new LoopGuardedAssetSaver($this->createMock(LoopGuard::class)),
            $logger ?? new NullLogger(),
        );
    }

    private function image(string $filename): Asset\Image
    {
        $asset = $this->createMock(Asset\Image::class);
        $asset->method('getFilename')->willReturn($filename);
        $asset->method('getId')->willReturn(42);

        return $asset;
    }

    private function delivery(): RuleActionDeliveryContextInterface
    {
        return $this->createMock(RuleActionDeliveryContextInterface::class);
    }
}
