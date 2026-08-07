<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Action;

use Oronts\AssetPilotBundle\Action\RuleActionDeliveryContextInterface;
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

            public function prepare(Asset $asset, AbstractObject $object, array $config): array
            {
                return $config;
            }

            public function applyPrepared(Asset $asset, array $payload, RuleActionDeliveryContextInterface $delivery): void {}
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

    #[Test]
    public function rejectsDuplicateActionTypes(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate rule action alias "set_property".');

        new RuleActionResolver([$this->action('set_property'), $this->action('set_property')]);
    }
}
