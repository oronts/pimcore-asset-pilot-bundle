<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Event;

use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class NonFatalEventDispatcher
{
    /** @return list<string> */
    public static function dispatch(
        EventDispatcherInterface $dispatcher,
        object $event,
        string $eventName,
        LoggerInterface $logger,
        array $context = [],
    ): array {
        if (!$dispatcher instanceof SymfonyEventDispatcherInterface) {
            try {
                $dispatcher->dispatch($event, $eventName);

                return [];
            } catch (\Throwable $e) {
                self::log($logger, $eventName, $e, $context);

                return [$e->getMessage()];
            }
        }

        $errors = [];
        foreach ($dispatcher->getListeners($eventName) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            try {
                $listener($event, $eventName, $dispatcher);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                self::log($logger, $eventName, $e, $context);
            }
        }

        return $errors;
    }

    private static function log(LoggerInterface $logger, string $eventName, \Throwable $e, array $context): void
    {
        $logger->error('Asset Pilot: observer failed for {event}: {error}', [
            ...$context,
            'event' => $eventName,
            'error' => $e->getMessage(),
            'exception' => $e,
        ]);
    }
}
