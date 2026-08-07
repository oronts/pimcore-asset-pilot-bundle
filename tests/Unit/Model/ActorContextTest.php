<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Model;

use Oronts\AssetPilotBundle\Enum\ActorType;
use Oronts\AssetPilotBundle\Model\ActorContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorContext::class)]
class ActorContextTest extends TestCase
{
    #[Test]
    public function userRequiresPositiveId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ActorContext::user(0);
    }

    #[Test]
    public function nonUserCannotCarryUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ActorContext(ActorType::System, 12);
    }
}
