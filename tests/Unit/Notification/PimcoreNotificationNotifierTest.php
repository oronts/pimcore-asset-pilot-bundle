<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Notification;

use Oronts\AssetPilotBundle\Enum\NotificationSeverity;
use Oronts\AssetPilotBundle\Notification\Notification;
use Oronts\AssetPilotBundle\Notification\PimcoreNotificationNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Bundle\StudioBackendBundle\Notification\Schema\SendNotificationParameters;
use Pimcore\Bundle\StudioBackendBundle\Notification\Service\SendNotificationServiceInterface;
use Psr\Log\NullLogger;

#[CoversClass(PimcoreNotificationNotifier::class)]
class PimcoreNotificationNotifierTest extends TestCase
{
    private function notification(): Notification
    {
        return new Notification('test.alert', NotificationSeverity::Warning, 'Title', 'Body');
    }

    /**
     * @param int[] $userIds
     * @param int[] $groupIds
     * @param int[] $validUsers
     * @param int[] $validGroups
     */
    private function notifier(SendNotificationServiceInterface $service, array $userIds = [], array $groupIds = [], array $validUsers = [], array $validGroups = []): PimcoreNotificationNotifier
    {
        return new class ($service, $userIds, $groupIds, $validUsers, $validGroups) extends PimcoreNotificationNotifier {
            /**
             * @param int[] $userIds
             * @param int[] $groupIds
             * @param int[] $validUsers
             * @param int[] $validGroups
             */
            public function __construct(SendNotificationServiceInterface $service, array $userIds, array $groupIds, private readonly array $validUsers, private readonly array $validGroups)
            {
                parent::__construct($service, new NullLogger(), $userIds, $groupIds, 0);
            }

            protected function userExists(int $userId): bool
            {
                return in_array($userId, $this->validUsers, true);
            }

            protected function groupExists(int $groupId): bool
            {
                return in_array($groupId, $this->validGroups, true);
            }
        };
    }

    #[Test]
    public function sendsToEveryConfiguredValidUser(): void
    {
        $recipients = [];
        $service = $this->createMock(SendNotificationServiceInterface::class);
        $service->expects(self::exactly(2))->method('sendNotification')
            ->willReturnCallback(static function (SendNotificationParameters $parameters) use (&$recipients): void {
                $recipients[] = $parameters->getRecipientId();
            });

        $this->notifier($service, userIds: [7, 8], validUsers: [7, 8])->notify($this->notification());

        self::assertSame([7, 8], $recipients);
    }

    #[Test]
    public function skipsUnknownUsersButStillNotifiesValidOnes(): void
    {
        $service = $this->createMock(SendNotificationServiceInterface::class);
        $service->expects(self::once())->method('sendNotification')
            ->with(
                self::callback(static fn (SendNotificationParameters $parameters): bool => $parameters->getRecipientId() === 7),
                null,
            );

        $this->notifier($service, userIds: [7, 99], validUsers: [7])->notify($this->notification());
    }

    #[Test]
    public function sendsToEveryConfiguredValidGroup(): void
    {
        $service = $this->createMock(SendNotificationServiceInterface::class);
        $service->expects(self::once())->method('sendNotification')
            ->with(
                self::callback(static fn (SendNotificationParameters $parameters): bool => $parameters->getRecipientId() === 3),
                null,
            );

        $this->notifier($service, groupIds: [3, 99], validGroups: [3])->notify($this->notification());
    }

    #[Test]
    public function notifiesBothUsersAndGroups(): void
    {
        $service = $this->createMock(SendNotificationServiceInterface::class);
        $service->expects(self::exactly(2))->method('sendNotification');

        $this->notifier($service, userIds: [7], groupIds: [3], validUsers: [7], validGroups: [3])->notify($this->notification());
    }

    #[Test]
    public function doesNothingWhenNoRecipientsAreConfigured(): void
    {
        $service = $this->createMock(SendNotificationServiceInterface::class);
        $service->expects(self::never())->method('sendNotification');

        $this->notifier($service)->notify($this->notification());
    }
}
