<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Observer;

use Oronts\AssetPilotBundle\Action\RuleActionResolver;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Model\DeliveryEnvelope;
use Oronts\AssetPilotBundle\Model\OperationIntent;
use Oronts\AssetPilotBundle\Model\PreparedDelivery;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;

class DurableRuleActionObserver implements DurableOperationObserverInterface
{
    public function __construct(private readonly RuleActionResolver $actions) {}

    public function id(): string
    {
        return 'rule_actions';
    }

    public function requiredAssetPermission(): ?string
    {
        return 'publish';
    }

    public function prepare(OperationIntent $intent): iterable
    {
        if ($intent->kind !== OperationKind::Move) {
            return [];
        }

        $configured = $intent->context['actions'] ?? [];
        if (!is_array($configured) || $configured === []) {
            return [];
        }

        $asset = $this->loadAsset($intent->assetId);
        $object = $this->loadObject($intent->objectId);
        if ($asset === null || $asset instanceof Asset\Folder || $object === null) {
            throw new \RuntimeException('Rule actions cannot be prepared because their asset or object is unavailable.');
        }

        $deliveries = [];
        foreach (array_values($configured) as $index => $config) {
            if (!is_array($config) || trim((string) ($config['type'] ?? '')) === '') {
                throw new \InvalidArgumentException('Every rule action requires a registered type.');
            }

            $type = (string) $config['type'];
            $action = $this->actions->resolve($type);
            if ($action === null) {
                throw new \InvalidArgumentException(sprintf('Rule action type "%s" is not registered.', $type));
            }

            $deliveries[] = new PreparedDelivery(
                $this->id(),
                sprintf('rule-action:%04d:%s', $index, $type),
                OperationDeliveryOutcome::Success,
                ['type' => $type, 'payload' => $action->prepare($asset, $object, $config)],
            );
        }

        return $deliveries;
    }

    public function deliver(DeliveryEnvelope $delivery): void
    {
        $type = (string) ($delivery->payload['type'] ?? '');
        $payload = $delivery->payload['payload'] ?? null;
        $action = $this->actions->resolve($type);
        if ($action === null || !is_array($payload)) {
            throw new \RuntimeException('The prepared rule action is no longer registered or has invalid data.');
        }

        $asset = $this->loadAsset($delivery->intent->assetId);
        if ($asset === null || $asset instanceof Asset\Folder) {
            throw new \RuntimeException('The rule action asset is unavailable.');
        }

        $action->applyPrepared($asset, $payload, $delivery);
    }

    protected function loadAsset(int $assetId): ?Asset
    {
        return Asset::getById($assetId, ['force' => true]);
    }

    protected function loadObject(int $objectId): ?AbstractObject
    {
        return AbstractObject::getById($objectId, ['force' => true]);
    }
}
