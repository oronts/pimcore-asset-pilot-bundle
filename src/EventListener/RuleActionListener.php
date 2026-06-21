<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Oronts\AssetPilotBundle\Action\RuleActionResolver;
use Oronts\AssetPilotBundle\Audit\AuditLoggerInterface;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Event\AssetMoveEvent;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;

/**
 * Applies a rule's configured `actions` to the asset after a successful move (POST_MOVE). Decoupled
 * from the organizer: post-move only (never dry-run), and each action is isolated so one failure
 * neither aborts the others nor undoes the move that already happened.
 */
class RuleActionListener
{
    public function __construct(
        protected readonly RuleActionResolver $resolver,
        protected readonly LoggerInterface $logger,
        protected readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function onPostMove(AssetMoveEvent $event): void
    {
        if ($event->isDryRun() || $event->rule->actions === []) {
            return;
        }

        foreach ($event->rule->actions as $config) {
            if (!is_array($config) || !isset($config['type'])) {
                continue;
            }

            $type = (string) $config['type'];
            $action = $this->resolver->resolve($type);
            if ($action === null) {
                $this->logger->warning('Asset Pilot: no rule action registered for type "{type}" (rule "{rule}").', [
                    'type' => $type,
                    'rule' => $event->rule->name,
                ]);
                continue;
            }

            try {
                $action->apply($event->asset, $event->object, $config);
            } catch (\Throwable $e) {
                $this->logger->error('Asset Pilot: rule action "{type}" failed for asset {id}: {error}', [
                    'type' => $type,
                    'id' => $event->asset->getId(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $this->recordActionFailure($event, $type, $e);
            }
        }
    }

    /**
     * Record a failed post-move action in the audit trail under a distinct status, so it surfaces in
     * the audit log and the audit-derived metrics without being counted as a failed move (the move
     * succeeded). Source equals target: the record describes an action outcome, not a move, so it can
     * never be reverted. Guarded so a throwing audit gateway cannot break action isolation.
     */
    private function recordActionFailure(AssetMoveEvent $event, string $type, \Throwable $e): void
    {
        try {
            $this->auditLogger->log(new MoveOperation(
                assetId: (int) $event->asset->getId(),
                sourcePath: $event->targetPath,
                targetPath: $event->targetPath,
                objectId: (int) $event->object->getId(),
                objectClass: $event->object instanceof Concrete ? $event->object->getClassName() : 'Folder',
                ruleName: $event->rule->name,
                status: OperationStatus::ActionFailed,
                triggerType: $event->triggerType,
                errorMessage: sprintf('rule action "%s" failed: %s', $type, $e->getMessage()),
                userId: $event->operation?->userId,
            ));
        } catch (\Throwable $auditError) {
            $this->logger->error('Asset Pilot: failed to record rule-action failure in the audit log: {error}', [
                'error' => $auditError->getMessage(),
            ]);
        }
    }
}
