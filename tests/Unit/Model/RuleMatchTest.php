<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Model;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(RuleMatch::class)]
class RuleMatchTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $rule = new Rule(
            name: 'test', class: 'Product', fields: [], condition: null,
            targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
            priority: 10, enabled: true, filters: [],
        );
        $object = $this->createMock(AbstractObject::class);
        $asset = $this->createMock(Asset::class);

        $match = new RuleMatch(
            rule: $rule,
            object: $object,
            asset: $asset,
            resolvedPath: '/products/images',
        );

        self::assertSame($rule, $match->rule);
        self::assertSame($object, $match->object);
        self::assertSame($asset, $match->asset);
        self::assertSame('/products/images', $match->resolvedPath);
        self::assertNull($match->locale);
    }

    #[Test]
    public function localeDefaultsToNull(): void
    {
        $match = new RuleMatch(
            rule: new Rule(
                name: 'test', class: 'Product', fields: [], condition: null,
                targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
                priority: 10, enabled: true, filters: [],
            ),
            object: $this->createMock(AbstractObject::class),
            asset: $this->createMock(Asset::class),
            resolvedPath: '/path',
        );

        self::assertNull($match->locale);
    }

    #[Test]
    public function localeCanBeSet(): void
    {
        $match = new RuleMatch(
            rule: new Rule(
                name: 'test', class: 'Product', fields: [], condition: null,
                targetPath: '/test', strategy: MoveStrategy::Always, callback: null,
                priority: 10, enabled: true, filters: [],
            ),
            object: $this->createMock(AbstractObject::class),
            asset: $this->createMock(Asset::class),
            resolvedPath: '/path',
            locale: 'de_DE',
        );

        self::assertSame('de_DE', $match->locale);
    }
}
