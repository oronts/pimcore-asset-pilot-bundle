<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Strategy;

use Oronts\AssetPilotBundle\Enum\MoveStrategy;
use Oronts\AssetPilotBundle\Model\Rule;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class CallbackStrategy implements ConflictStrategyInterface
{
    /**
     * @param ContainerInterface $callbacks service locator scoped to services tagged
     *                                      `oronts_asset_pilot.callback`, keyed by service id
     */
    public function __construct(
        protected readonly ContainerInterface $callbacks,
        protected readonly LoggerInterface $logger,
    ) {}

    public function resolve(Asset $asset, AbstractObject $currentObject, Rule $rule, bool $dryRun): bool
    {
        if ($rule->callback === null) {
            $this->logger->warning('CallbackStrategy: no callback configured for rule "{rule}"', [
                'rule' => $rule->name,
            ]);
            return false;
        }

        if (!$this->callbacks->has($rule->callback)) {
            $this->logger->error('CallbackStrategy: callback service "{service}" not found for rule "{rule}". Tag it with "oronts_asset_pilot.callback".', [
                'service' => $rule->callback,
                'rule' => $rule->name,
            ]);
            return false;
        }

        $callback = $this->callbacks->get($rule->callback);

        // Plain callables remain useful for small project-local decisions; interface services provide
        // the typed, auto-tagged extension contract.
        if ($callback instanceof CallbackDecisionInterface) {
            $result = $callback->decide($asset, $currentObject, $rule, $dryRun);
        } elseif (is_callable($callback)) {
            $result = $callback($asset, $currentObject, $rule, $dryRun);
        } else {
            $this->logger->error('CallbackStrategy: service "{service}" must implement CallbackDecisionInterface or be callable', [
                'service' => $rule->callback,
            ]);
            return false;
        }

        $this->logger->debug('CallbackStrategy: callback for rule "{rule}" returned {result}', [
            'rule' => $rule->name,
            'result' => $result ? 'true' : 'false',
        ]);

        return (bool) $result;
    }

    public function supports(MoveStrategy $strategy): bool
    {
        return $strategy === MoveStrategy::Callback;
    }
}
