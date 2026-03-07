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
    public function __construct(
        protected readonly ContainerInterface $container,
        protected readonly LoggerInterface $logger,
    ) {}

    public function resolve(Asset $asset, AbstractObject $currentObject, Rule $rule): bool
    {
        if ($rule->callback === null) {
            $this->logger->warning('CallbackStrategy: no callback configured for rule "{rule}"', [
                'rule' => $rule->name,
            ]);
            return false;
        }

        if (!$this->container->has($rule->callback)) {
            $this->logger->error('CallbackStrategy: service "{service}" not found for rule "{rule}"', [
                'service' => $rule->callback,
                'rule' => $rule->name,
            ]);
            return false;
        }

        $callback = $this->container->get($rule->callback);
        if (!is_callable($callback)) {
            $this->logger->error('CallbackStrategy: service "{service}" is not callable', [
                'service' => $rule->callback,
            ]);
            return false;
        }

        $result = $callback($asset, $currentObject, $rule);

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
