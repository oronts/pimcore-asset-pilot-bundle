<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Enum;

use Oronts\AssetPilotBundle\Enum\PropertyType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PropertyType::class)]
class PropertyTypeTest extends TestCase
{
    #[Test]
    public function exposesItsStringValues(): void
    {
        self::assertSame(['text', 'bool', 'select'], PropertyType::values());
    }
}
