<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Notification;

use Oronts\AssetPilotBundle\Notification\PimcoreNotificationNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Notification\Service\NotificationService;
use Psr\Log\NullLogger;

#[CoversClass(PimcoreNotificationNotifier::class)]
class PimcoreNotificationNotifierTest extends TestCase
{
    /**
     * @param int[] $userIds
     * @param int[] $groupIds
     * @param int[] $validUsers
     * @param int[] $validGroups
     */
    private function notifier(NotificationService $service, array $userIds = [], array $groupIds = [], array $validUsers = [], array $validGroups = []): PimcoreNotificationNotifier
    {
        return new class ($service, $userIds, $groupIds, $validUsers, $validGroups) extends PimcoreNotificationNotifier {
            /**
             * @param int[] $userIds
             * @param int[] $groupIds
             * @param int[] $validUsers
             * @param int[] $validGroups
             */
            public function __construct(NotificationService $service, array $userIds, array $groupIds, private readonly array $validUsers, private readonly array $validGroups)
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
        $service = $this->createMock(NotificationService::class);
        $service->expects(self::exactly(2))->method('sendToUser');
        $service->expects(self::never())->method('sendToGroup');

        $this->notifier($service, userIds: [7, 8], validUsers: [7, 8])->notify('Title', 'Body');
    }

    #[Test]
    public function skipsUnknownUsersButStillNotifiesValidOnes(): void
    {
        $service = $this->createMock(NotificationService::class);
        // Only the valid id 7 is sent; 99 is skipped before sendToUser (which would leak a transaction).
        $service->expects(self::once())->method('sendToUser')->with(7, 0, 'Title', 'Body');

        $this->notifier($service, userIds: [7, 99], validUsers: [7])->notify('Title', 'Body');
    }

    #[Test]
    public function sendsToEveryConfiguredValidGroup(): void
    {
        $service = $this->createMock(NotificationService::class);
        $service->expects(self::once())->method('sendToGroup')->with(3, 0, 'Title', 'Body');
        $service->expects(self::never())->method('sendToUser');

        $this->notifier($service, groupIds: [3, 99], validGroups: [3])->notify('Title', 'Body');
    }

    #[Test]
    public function notifiesBothUsersAndGroups(): void
    {
        $service = $this->createMock(NotificationService::class);
        $service->expects(self::once())->method('sendToUser');
        $service->expects(self::once())->method('sendToGroup');

        $this->notifier($service, userIds: [7], groupIds: [3], validUsers: [7], validGroups: [3])->notify('Title', 'Body');
    }

    #[Test]
    public function doesNothingWhenNoRecipientsAreConfigured(): void
    {
        $service = $this->createMock(NotificationService::class);
        $service->expects(self::never())->method('sendToUser');
        $service->expects(self::never())->method('sendToGroup');

        $this->notifier($service)->notify('Title', 'Body');
    }
}
