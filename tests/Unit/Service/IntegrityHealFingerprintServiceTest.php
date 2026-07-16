<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\HealOutcome;
use Oronts\AssetPilotBundle\Enum\IntegrityStatus;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Integrity\CompositeIntegrityChecker;
use Oronts\AssetPilotBundle\Integrity\IntegrityCheckerInterface;
use Oronts\AssetPilotBundle\Model\HealResult;
use Oronts\AssetPilotBundle\Model\IntegrityResult;
use Oronts\AssetPilotBundle\Service\IntegrityHealFingerprintService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\Version;

#[CoversClass(IntegrityHealFingerprintService::class)]
final class IntegrityHealFingerprintServiceTest extends TestCase
{
    #[Test]
    public function fingerprintTracksLiveVersionProtectionAndSelectedCheckerState(): void
    {
        $path = '/Products/photo.jpg';
        $modifiedAt = 100;
        $checksum = 'live-a';
        $locked = false;
        $preferHighPriorityChecker = new \ArrayObject(['value' => true]);
        $asset = $this->asset($path, $modifiedAt, $checksum, $locked);
        $version = $this->version(11, 'version-a');
        $service = $this->service($asset, [$version], $this->checker($preferHighPriorityChecker));
        $baseline = $service->fingerprintMap([7])['asset:7'];

        $path = '/Archive/photo.jpg';
        self::assertNotSame($baseline, $service->fingerprintMap([7])['asset:7']);
        $path = '/Products/photo.jpg';
        $modifiedAt = 101;
        self::assertNotSame($baseline, $service->fingerprintMap([7])['asset:7']);
        $modifiedAt = 100;
        $checksum = 'live-b';
        self::assertNotSame($baseline, $service->fingerprintMap([7])['asset:7']);
        $checksum = 'live-a';
        $locked = true;
        self::assertNotSame($baseline, $service->fingerprintMap([7])['asset:7']);
        $locked = false;
        $version->setBinaryFileHash('version-b');
        self::assertNotSame($baseline, $service->fingerprintMap([7])['asset:7']);
        $version->setBinaryFileHash('version-a');
        $preferHighPriorityChecker['value'] = false;
        self::assertNotSame($baseline, $service->fingerprintMap([7])['asset:7']);
    }

    #[Test]
    public function targetsAreSortedAndBindTheExactPreviewResult(): void
    {
        $service = $this->service($this->asset(), [], $this->checker());
        $states = $service->fingerprintMap([9, 7]);
        $preview = [
            7 => new HealResult(HealOutcome::Healed, 'image', 11, dryRun: true),
            9 => new HealResult(HealOutcome::Unrecoverable, 'image', reason: 'No version.', dryRun: true),
        ];
        $targets = $service->targets([9, 7], $states, $preview);

        self::assertSame(['asset:7', 'asset:9'], array_column($targets, 'id'));

        $changedPreview = $preview;
        $changedPreview[7] = new HealResult(HealOutcome::Healed, 'image', 12, dryRun: true);
        $changedTargets = $service->targets([9, 7], $states, $changedPreview);

        self::assertNotSame($targets[0]->fingerprint, $changedTargets[0]->fingerprint);
    }

    #[Test]
    public function fingerprintTracksTheSelectedCheckersLiveOutcome(): void
    {
        $status = new \ArrayObject(['value' => IntegrityStatus::Broken]);
        $service = $this->service($this->asset(), [], $this->checker(liveStatus: $status));
        $broken = $service->fingerprintMap([7])['asset:7'];

        $status['value'] = IntegrityStatus::Renderable;

        self::assertNotSame($broken, $service->fingerprintMap([7])['asset:7']);
    }

    #[Test]
    public function assertionRejectsAChangedPreviewFingerprint(): void
    {
        $asset = $this->asset();
        $service = $this->service($asset, [], $this->checker());
        $result = new HealResult(HealOutcome::AlreadyRenderable, 'image', dryRun: true);
        $state = $service->fingerprintMap([7]);
        $target = $service->targets([7], $state, [7 => $result])[0];

        $service->assertUnchanged(7, $asset, $result, $target->fingerprint);

        $this->expectException(StaleApplyPlanException::class);
        $service->assertUnchanged(
            7,
            $asset,
            new HealResult(HealOutcome::Unverifiable, 'image', reason: 'changed', dryRun: true),
            $target->fingerprint,
        );
    }

    #[Test]
    public function planConfigurationContainsEveryIntegritySideEffectSetting(): void
    {
        $configuration = [
            'integrity' => ['enabled' => true, 'on_unrecoverable' => 'quarantine'],
            'protection' => ['exclude_folders' => ['/Manual'], 'lock_property' => 'ignored'],
            'quarantine' => ['folder' => '/Review'],
            'content_scan' => ['enabled' => true],
            'notifications' => ['enabled' => true],
        ];
        $service = $this->service($this->asset(), [], $this->checker(), $configuration, 'immutable');

        self::assertSame([
            'version' => 1,
            'integrity' => $configuration['integrity'],
            'protection' => ['exclude_folders' => ['/Manual'], 'lock_property' => 'immutable'],
            'quarantine' => $configuration['quarantine'],
            'content_scan' => $configuration['content_scan'],
            'notifications' => $configuration['notifications'],
        ], $service->planConfig());
    }

    private function asset(
        string &$path = null,
        int &$modifiedAt = null,
        string &$checksum = null,
        bool &$locked = null,
    ): Asset {
        $path ??= '/Products/photo.jpg';
        $modifiedAt ??= 100;
        $checksum ??= 'live-a';
        $locked ??= false;
        $asset = $this->createMock(Asset::class);
        $asset->method('getId')->willReturn(7);
        $asset->method('getRealFullPath')->willReturnCallback(static function () use (&$path): string {
            return $path;
        });
        $asset->method('getModificationDate')->willReturnCallback(static function () use (&$modifiedAt): int {
            return $modifiedAt;
        });
        $asset->method('getChecksum')->willReturnCallback(static function () use (&$checksum): string {
            return $checksum;
        });
        $asset->method('hasProperty')->willReturnCallback(static function () use (&$locked): bool {
            return $locked;
        });
        $asset->method('getProperty')->willReturnCallback(static function () use (&$locked): bool {
            return $locked;
        });

        return $asset;
    }

    private function version(int $id, string $binaryHash): Version
    {
        $version = (new \ReflectionClass(Version::class))->newInstanceWithoutConstructor();
        $version->setId($id);
        $version->setCid(7);
        $version->setDate(50);
        $version->setBinaryFileHash($binaryHash);
        $version->setBinaryFileId(3);
        $version->setStorageType('filesystem');

        return $version;
    }

    private function checker(
        ?\ArrayObject $preferHighPriority = null,
        ?\ArrayObject $liveStatus = null,
    ): CompositeIntegrityChecker {
        $preferHighPriority ??= new \ArrayObject(['value' => true]);
        $liveStatus ??= new \ArrayObject(['value' => IntegrityStatus::Broken]);
        $high = new class ($preferHighPriority, $liveStatus) implements IntegrityCheckerInterface {
            public function __construct(
                private readonly \ArrayObject $enabled,
                private readonly \ArrayObject $liveStatus,
            ) {}

            public function priority(): int
            {
                return 20;
            }

            public function supports(Asset $asset): bool
            {
                return $this->enabled['value'];
            }

            public function check(Asset $asset): IntegrityResult
            {
                return new IntegrityResult($this->liveStatus['value'], 'high');
            }

            public function checkBinary(string $binary, string $extension): IntegrityResult
            {
                return new IntegrityResult(IntegrityStatus::Renderable, 'high');
            }
        };
        $fallback = new class () implements IntegrityCheckerInterface {
            public function priority(): int
            {
                return 10;
            }

            public function supports(Asset $asset): bool
            {
                return true;
            }

            public function check(Asset $asset): IntegrityResult
            {
                return new IntegrityResult(IntegrityStatus::Broken, 'fallback');
            }

            public function checkBinary(string $binary, string $extension): IntegrityResult
            {
                return new IntegrityResult(IntegrityStatus::Renderable, 'fallback');
            }
        };

        return new CompositeIntegrityChecker([$fallback, $high]);
    }

    /** @param list<Version> $versions @param array<string, mixed> $configuration */
    private function service(
        Asset $asset,
        array $versions,
        CompositeIntegrityChecker $checker,
        array $configuration = [],
        string $lockProperty = 'asset_pilot_locked',
    ): IntegrityHealFingerprintService {
        return new class ($checker, $configuration, $lockProperty, $asset, $versions) extends IntegrityHealFingerprintService {
            /** @param array<string, mixed> $configuration @param list<Version> $versions */
            public function __construct(
                CompositeIntegrityChecker $checker,
                array $configuration,
                string $lockProperty,
                private readonly Asset $asset,
                private readonly array $versions,
            ) {
                parent::__construct($checker, $configuration, $lockProperty);
            }

            protected function loadAsset(int $assetId): ?Asset
            {
                return $assetId === 7 ? $this->asset : null;
            }

            protected function loadVersions(Asset $asset): array
            {
                return $this->versions;
            }
        };
    }
}
