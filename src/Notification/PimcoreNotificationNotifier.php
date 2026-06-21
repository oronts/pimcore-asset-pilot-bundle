<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Notification;

use Pimcore\Model\Notification\Service\NotificationService;
use Pimcore\Model\User;
use Psr\Log\LoggerInterface;

/**
 * The built-in notifier: sends a Pimcore in-app notification (the backend "bell") to every configured
 * user and group via the native NotificationService, so alerts reach admins without the bundle adding
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
        protected readonly NotificationService $notificationService,
        protected readonly LoggerInterface $logger,
        protected readonly array $recipientUserIds = [],
        protected readonly array $recipientGroupIds = [],
        protected readonly int $senderUserId = 0,
    ) {}

    public function notify(string $title, string $message): void
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
            // Validate first: NotificationService::sendToUser() opens a DB transaction before checking
            // the recipient and throws without rolling back, so an unknown id would leak an open
            // transaction. Skipping unknown ids here keeps the rest of the allow list working.
            if (!$this->userExists($userId)) {
                $this->logger->warning('Asset Pilot: skipping notification to unknown user {id}.', ['id' => $userId]);
                continue;
            }
            $this->notificationService->sendToUser($userId, $this->senderUserId, $title, $message);
        }

        foreach ($this->recipientGroupIds as $groupId) {
            $groupId = (int) $groupId;
            if (!$this->groupExists($groupId)) {
                $this->logger->warning('Asset Pilot: skipping notification to unknown group {id}.', ['id' => $groupId]);
                continue;
            }
            $this->notificationService->sendToGroup($groupId, $this->senderUserId, $title, $message);
        }
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
