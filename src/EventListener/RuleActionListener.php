<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\EventListener;

use Oronts\AssetPilotBundle\Action\RuleActionResolver;
use Oronts\AssetPilotBundle\Event\AssetMoveEvent;
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
            }
        }
    }
}
