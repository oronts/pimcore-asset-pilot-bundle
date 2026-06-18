<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Action;

use Oronts\AssetPilotBundle\Action\RuleActionInterface;
use Oronts\AssetPilotBundle\Action\RuleActionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

#[CoversClass(RuleActionResolver::class)]
class RuleActionResolverTest extends TestCase
{
    private function action(string $type): RuleActionInterface
    {
        return new class ($type) implements RuleActionInterface {
            public function __construct(private string $type) {}

            public function getType(): string
            {
                return $this->type;
            }

            public function apply(Asset $asset, AbstractObject $object, array $config): void {}
        };
    }

    #[Test]
    public function resolvesAnActionByItsType(): void
    {
        $setProperty = $this->action('set_property');
        $resolver = new RuleActionResolver([$setProperty, $this->action('assign_tag')]);

        self::assertSame($setProperty, $resolver->resolve('set_property'));
    }

    #[Test]
    public function returnsNullForAnUnknownType(): void
    {
        $resolver = new RuleActionResolver([$this->action('set_property')]);

        self::assertNull($resolver->resolve('nope'));
    }
}
