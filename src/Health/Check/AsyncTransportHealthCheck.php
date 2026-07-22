<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Health\HealthCheckInterface;
use Oronts\AssetPilotBundle\Health\WorkerHeartbeatRecorder;
use Oronts\AssetPilotBundle\Message\BulkOrganizeMessage;
use Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage;
use Oronts\AssetPilotBundle\Message\OperationDeliveryMessage;
use Oronts\AssetPilotBundle\Message\OrganizeAssetsMessage;
use Oronts\AssetPilotBundle\Model\HealthCheckResult;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

class AsyncTransportHealthCheck implements HealthCheckInterface
{
    public function __construct(
        protected readonly bool $asyncEnabled,
        protected readonly int $batchSize,
        protected readonly ?CacheItemPoolInterface $cache = null,
        protected readonly int $heartbeatMaxAge = 120,
        protected readonly ?ContainerInterface $receivers = null,
        protected readonly ?SendersLocatorInterface $senders = null,
        protected readonly string $transportName = 'asset_pilot',
        protected readonly string $failureTransportName = 'asset_pilot_failed',
        protected readonly int $maxQueueDepth = 1000,
    ) {}

    public function name(): string
    {
        return 'async_transport';
    }

    public function run(): HealthCheckResult
    {
        if ($this->cache === null) {
            return $this->unverifiedResult();
        }

        try {
            $heartbeats = $this->readHeartbeats($this->requiredTransports());
        } catch (\Throwable) {
            return $this->unverifiedResult('Worker heartbeat cache could not be read.');
        }

        $staleHeartbeats = array_filter(
            $heartbeats,
            fn (array $heartbeat): bool => !$heartbeat['fresh'],
        );
        $transportDetails = $this->inspectTransports();

        if ($staleHeartbeats !== [] || $transportDetails['critical'] !== []) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Critical,
                implode(' ', [...($staleHeartbeats !== [] ? ['Required Messenger consumers are missing or stale.'] : []), ...$transportDetails['critical']]),
                $this->details($heartbeats, $transportDetails),
            );
        }

        if ($transportDetails['warnings'] !== []) {
            return new HealthCheckResult(
                $this->name(),
                HealthStatus::Warning,
                implode(' ', $transportDetails['warnings']),
                $this->details($heartbeats, $transportDetails),
            );
        }

        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Ok,
            'Required Messenger consumers, routing, queues, and failure transport are healthy.',
            $this->details($heartbeats, $transportDetails),
        );
    }

    /**
     * @param list<string> $transportNames
     *
     * @return array<string, array{fresh: bool, age_seconds: ?int, last_seen_at: ?string}>
     */
    protected function readHeartbeats(array $transportNames): array
    {
        $heartbeats = [];

        foreach ($transportNames as $transportName) {
            $item = $this->cache->getItem(WorkerHeartbeatRecorder::cacheKey($transportName));
            $value = $item->isHit() ? $item->get() : null;
            $timestamp = is_int($value) ? $value : null;
            $age = $timestamp === null ? null : max(0, $this->now() - $timestamp);
            $heartbeats[$transportName] = [
                'fresh' => $age !== null && $age <= $this->heartbeatMaxAge,
                'age_seconds' => $age,
                'last_seen_at' => $timestamp === null ? null : gmdate(DATE_ATOM, $timestamp),
            ];
        }

        return $heartbeats;
    }

    /** @param array<string, array{fresh: bool, age_seconds: ?int, last_seen_at: ?string}> $heartbeats */
    private function details(array $heartbeats, array $transportDetails = []): array
    {
        return [
            'async_enabled' => $this->asyncEnabled,
            'batch_size' => $this->batchSize,
            'heartbeat_max_age' => $this->heartbeatMaxAge,
            'heartbeats' => $heartbeats,
            'consumer_commands' => $this->consumerCommands(),
            'transports' => $transportDetails['transports'] ?? [],
            'routing' => $transportDetails['routing'] ?? [],
        ];
    }

    private function unverifiedResult(string $message = 'Worker heartbeat storage is not configured.'): HealthCheckResult
    {
        return new HealthCheckResult(
            $this->name(),
            HealthStatus::Warning,
            $message,
            [
                'async_enabled' => $this->asyncEnabled,
                'batch_size' => $this->batchSize,
                'consumer_commands' => $this->consumerCommands(),
            ],
        );
    }

    /**
     * @return array{
     *     critical: list<string>,
     *     warnings: list<string>,
     *     transports: array<string, array{configured: bool, message_count: ?int}>,
     *     routing: array<string, list<string>>
     * }
     */
    private function inspectTransports(): array
    {
        $details = ['critical' => [], 'warnings' => [], 'transports' => [], 'routing' => []];
        if ($this->receivers === null) {
            $details['warnings'][] = 'Messenger receiver discovery is unavailable.';

            return $details;
        }

        $required = $this->requiredTransports();
        $required[] = $this->failureTransportName;

        foreach (array_values(array_unique($required)) as $name) {
            $configured = $this->receivers->has($name);
            $count = null;
            if ($configured) {
                try {
                    $receiver = $this->receivers->get($name);
                    $count = $receiver instanceof MessageCountAwareInterface ? $receiver->getMessageCount() : null;
                } catch (\Throwable) {
                    $details['critical'][] = sprintf('Messenger transport "%s" could not be inspected.', $name);
                }
            } else {
                $details['critical'][] = sprintf('Required Messenger transport "%s" is not configured.', $name);
            }

            $details['transports'][$name] = ['configured' => $configured, 'message_count' => $count];
        }

        $queueCount = $details['transports'][$this->transportName]['message_count'] ?? null;
        if ($queueCount !== null && $queueCount > $this->maxQueueDepth) {
            $details['warnings'][] = sprintf('Messenger transport "%s" has %d queued messages, above the configured limit of %d.', $this->transportName, $queueCount, $this->maxQueueDepth);
        }
        $failedCount = $details['transports'][$this->failureTransportName]['message_count'] ?? null;
        if ($failedCount !== null && $failedCount > 0) {
            $details['warnings'][] = sprintf('Failure transport "%s" contains %d message(s).', $this->failureTransportName, $failedCount);
        }

        $details['routing'] = $this->inspectRouting();
        foreach ($details['routing'] as $message => $senders) {
            if (!in_array($this->transportName, $senders, true)) {
                $details['critical'][] = sprintf('Message "%s" is not routed to transport "%s".', $message, $this->transportName);
            }
        }

        return $details;
    }

    /** @return array<string, list<string>> */
    private function inspectRouting(): array
    {
        if ($this->senders === null) {
            return array_fill_keys(array_map(static fn (object $message): string => $message::class, $this->routedMessages()), []);
        }

        $routing = [];
        foreach ($this->routedMessages() as $message) {
            try {
                $routing[$message::class] = array_keys(iterator_to_array($this->senders->getSenders(new Envelope($message))));
            } catch (\Throwable) {
                $routing[$message::class] = [];
            }
        }

        return $routing;
    }

    /** @return list<string> */
    private function consumerCommands(): array
    {
        return array_map(static fn (string $name): string => 'bin/console messenger:consume ' . $name, $this->requiredTransports());
    }

    /** @return list<string> */
    private function requiredTransports(): array
    {
        return [$this->transportName, 'pimcore_maintenance'];
    }

    /** @return list<object> */
    private function routedMessages(): array
    {
        // The durable delivery outbox and the dependency-projection repair route are BOTH required
        // regardless of whether organization itself runs synchronously; validate them unconditionally.
        $messages = [
            new OperationDeliveryMessage('health-check'),
            new DependencyProjectionRefreshMessage('asset', 1),
        ];
        if ($this->asyncEnabled) {
            $messages[] = new OrganizeAssetsMessage(1, TriggerType::Api);
            $messages[] = new BulkOrganizeMessage([1], TriggerType::Api);
        }

        return $messages;
    }

    protected function now(): int
    {
        return time();
    }
}
