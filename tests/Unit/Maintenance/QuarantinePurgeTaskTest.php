<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Maintenance;

use Oronts\AssetPilotBundle\Maintenance\QuarantinePurgeTask;
use Oronts\AssetPilotBundle\Service\QuarantineService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(QuarantinePurgeTask::class)]
class QuarantinePurgeTaskTest extends TestCase
{
    #[Test]
    public function runsThePurgeOnTheMaintenanceRun(): void
    {
        $service = $this->createMock(QuarantineService::class);
        $service->expects(self::once())->method('purgeExpired')->willReturn(['purged' => 2, 'skipped' => 0, 'failed' => 0]);

        (new QuarantinePurgeTask($service, new NullLogger()))->execute();
    }

    #[Test]
    public function swallowsExceptionsSoTheMaintenanceRunNeverFails(): void
    {
        $this->expectNotToPerformAssertions();

        $service = $this->createMock(QuarantineService::class);
        $service->method('purgeExpired')->willThrowException(new \RuntimeException('db gone'));

        (new QuarantinePurgeTask($service, new NullLogger()))->execute();
    }
}
