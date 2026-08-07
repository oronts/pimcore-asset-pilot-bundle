<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Notification;

use Pimcore\Bundle\StudioBackendBundle\Notification\Schema\SendNotificationParameters;
use Pimcore\Bundle\StudioBackendBundle\Notification\Service\SendNotificationServiceInterface;
use Pimcore\Model\User;
use Pimcore\Model\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * The built-in notifier: sends a Pimcore in-app notification (the backend "bell") to every configured
 * user and group via the Studio notification service, so alerts reach admins without the bundle adding
 * a mailer or outbound HTTP. Inert (logs once) until at least one recipient is configured.
 */
class PimcoreNotificationNotifier implements NotifierInterface
{
    private bool $missingRecipientWarned = false;

    /**
     * @param int[] $recipientUserIds
     * @param int[] $recipientGroupIds
     */
    public function __construct(
        protected readonly SendNotificationServiceInterface $notificationService,
        protected readonly LoggerInterface $logger,
        protected readonly array $recipientUserIds = [],
        protected readonly array $recipientGroupIds = [],
        protected readonly int $senderUserId = 0,
    ) {}

    public function notify(Notification $notification): void
    {
        if ($this->recipientUserIds === [] && $this->recipientGroupIds === []) {
            if (!$this->missingRecipientWarned) {
                $this->logger->warning('Asset Pilot: notifications enabled but no recipient_user_ids / recipient_group_ids configured; in-app notifications skipped.');
                $this->missingRecipientWarned = true;
            }

            return;
        }

        foreach ($this->recipientUserIds as $userId) {
            $userId = (int) $userId;
            if (!$this->userExists($userId)) {
                $this->logger->warning('Asset Pilot: skipping notification to unknown user {id}.', ['id' => $userId]);
                continue;
            }
            $this->send($userId, $notification);
        }

        foreach ($this->recipientGroupIds as $groupId) {
            $groupId = (int) $groupId;
            if (!$this->groupExists($groupId)) {
                $this->logger->warning('Asset Pilot: skipping notification to unknown group {id}.', ['id' => $groupId]);
                continue;
            }
            $this->send($groupId, $notification);
        }
    }

    private function send(int $recipientId, Notification $notification): void
    {
        $this->notificationService->sendNotification(
            new SendNotificationParameters($recipientId, $notification->title, $notification->message),
            $this->sender(),
        );
    }

    protected function sender(): ?UserInterface
    {
        if ($this->senderUserId === 0) {
            return null;
        }

        $sender = User::getById($this->senderUserId);

        return $sender instanceof User ? $sender : null;
    }

    protected function userExists(int $userId): bool
    {
        return User::getById($userId) instanceof User;
    }

    protected function groupExists(int $groupId): bool
    {
        return User\Role::getById($groupId) instanceof User\Role;
    }
}
