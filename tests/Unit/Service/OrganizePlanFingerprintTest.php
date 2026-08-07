<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Service\OrganizePlanFingerprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;

#[CoversClass(OrganizePlanFingerprint::class)]
final class OrganizePlanFingerprintTest extends TestCase
{
    #[Test]
    public function operationOrderDoesNotChangeTheFingerprint(): void
    {
        $fingerprints = new OrganizePlanFingerprint();
        $object = $this->object();
        $first = $this->operation(10, '/organized/a.jpg');
        $second = $this->operation(11, '/organized/b.jpg');

        self::assertSame(
            $fingerprints->forOperations($object, [$first, $second]),
            $fingerprints->forOperations($object, [$second, $first]),
        );
    }

    #[Test]
    public function objectStateAndPreviewOperationsAreBothBound(): void
    {
        $fingerprints = new OrganizePlanFingerprint();
        $operation = $this->operation(10, '/organized/a.jpg');
        $baseline = $fingerprints->forOperations($this->object(), [$operation]);

        self::assertNotSame($baseline, $fingerprints->forOperations($this->object(modifiedAt: 101), [$operation]));
        self::assertNotSame($baseline, $fingerprints->forOperations($this->object(version: 4), [$operation]));
        self::assertNotSame($baseline, $fingerprints->forOperations($this->object(path: '/changed/product'), [$operation]));
        self::assertNotSame($baseline, $fingerprints->forOperations($this->object(), [$this->operation(10, '/changed/a.jpg')]));
    }

    private function object(int $modifiedAt = 100, int $version = 3, string $path = '/products/product'): Concrete
    {
        $object = $this->createMock(Concrete::class);
        $object->method('getId')->willReturn(42);
        $object->method('getClassName')->willReturn('Product');
        $object->method('getRealFullPath')->willReturn($path);
        $object->method('getModificationDate')->willReturn($modifiedAt);
        $object->method('getType')->willReturn('object');
        $object->method('getVersionCount')->willReturn($version);

        return $object;
    }

    private function operation(int $assetId, string $targetPath): MoveOperation
    {
        return new MoveOperation(
            assetId: $assetId,
            sourcePath: '/incoming/' . $assetId . '.jpg',
            targetPath: $targetPath,
            objectId: 42,
            objectClass: 'Product',
            ruleName: 'product-assets',
            status: OperationStatus::Pending,
            triggerType: TriggerType::Api,
        );
    }
}
