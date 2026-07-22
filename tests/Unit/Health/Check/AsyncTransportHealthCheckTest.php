<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Health\Check;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\Check\AsyncTransportHealthCheck;
use Oronts\AssetPilotBundle\Health\WorkerHeartbeatRecorder;
use Oronts\AssetPilotBundle\Message\DependencyProjectionRefreshMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

#[CoversClass(AsyncTransportHealthCheck::class)]
class AsyncTransportHealthCheckTest extends TestCase
{
    #[Test]
    public function synchronousOrganizationStillRequiresDeliveryAndMaintenanceConsumers(): void
    {
        $result = (new AsyncTransportHealthCheck(false, 50))->run();

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertFalse($result->details['async_enabled']);
        self::assertSame([
            'bin/console messenger:consume asset_pilot',
            'bin/console messenger:consume pimcore_maintenance',
        ], $result->details['consumer_commands']);
    }

    #[Test]
    public function warnsUntilBothConsumersAreVerifiedWhenAsyncEnabled(): void
    {
        $result = (new AsyncTransportHealthCheck(true, 100))->run();

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertTrue($result->details['async_enabled']);
        self::assertSame(100, $result->details['batch_size']);
        self::assertSame([
            'bin/console messenger:consume asset_pilot',
            'bin/console messenger:consume pimcore_maintenance',
        ], $result->details['consumer_commands']);
    }

    #[Test]
    public function isOkWhenBothConsumerHeartbeatsAreFresh(): void
    {
        $cache = new ArrayAdapter();
        foreach (WorkerHeartbeatRecorder::REQUIRED_TRANSPORTS as $transportName) {
            $item = $cache->getItem(WorkerHeartbeatRecorder::cacheKey($transportName));
            $item->set(950);
            $cache->save($item);
        }

        $result = $this->check($cache, 1000)->run();

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame(50, $result->details['heartbeats']['asset_pilot']['age_seconds']);
    }

    #[Test]
    public function isCriticalWhenARequiredConsumerHeartbeatIsMissing(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem(WorkerHeartbeatRecorder::cacheKey('asset_pilot'));
        $item->set(950);
        $cache->save($item);

        $result = $this->check($cache, 1000)->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertFalse($result->details['heartbeats']['pimcore_maintenance']['fresh']);
    }

    #[Test]
    public function isCriticalWhenAConsumerHeartbeatIsStale(): void
    {
        $cache = new ArrayAdapter();
        foreach (WorkerHeartbeatRecorder::REQUIRED_TRANSPORTS as $transportName) {
            $item = $cache->getItem(WorkerHeartbeatRecorder::cacheKey($transportName));
            $item->set($transportName === 'asset_pilot' ? 800 : 950);
            $cache->save($item);
        }

        self::assertSame(HealthStatus::Critical, $this->check($cache, 1000)->run()->status);
    }

    #[Test]
    public function synchronousOrganizationIsOkWhenDurableDeliveryInfrastructureIsHealthy(): void
    {
        $cache = new ArrayAdapter();
        foreach (WorkerHeartbeatRecorder::REQUIRED_TRANSPORTS as $transportName) {
            $item = $cache->getItem(WorkerHeartbeatRecorder::cacheKey($transportName));
            $item->set(950);
            $cache->save($item);
        }
        $check = new class (false, 50, $cache, 120, $this->receivers([
            'asset_pilot' => 0,
            'pimcore_maintenance' => 0,
            'asset_pilot_failed' => 0,
        ]), $this->senders('asset_pilot')) extends AsyncTransportHealthCheck {
            public function __construct(bool $enabled, int $batchSize, ArrayAdapter $cache, int $maxAge, ContainerInterface $receivers, SendersLocatorInterface $senders)
            {
                parent::__construct($enabled, $batchSize, $cache, $maxAge, $receivers, $senders);
            }

            protected function now(): int
            {
                return 1000;
            }
        };

        $result = $check->run();

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertArrayHasKey('asset_pilot', $result->details['heartbeats']);
        self::assertArrayHasKey('pimcore_maintenance', $result->details['heartbeats']);
    }

    #[Test]
    public function isCriticalWhenTheFailureTransportIsMissing(): void
    {
        $result = $this->check(
            $this->heartbeats(),
            1000,
            $this->receivers(['asset_pilot' => 0, 'pimcore_maintenance' => 0]),
        )->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertStringContainsString('asset_pilot_failed', $result->message);
    }

    #[Test]
    public function warnsWhenQueuedOrFailedMessagesNeedAttention(): void
    {
        $result = $this->check(
            $this->heartbeats(),
            1000,
            $this->receivers(['asset_pilot' => 1001, 'pimcore_maintenance' => 0, 'asset_pilot_failed' => 2]),
        )->run();

        self::assertSame(HealthStatus::Warning, $result->status);
        self::assertSame(1001, $result->details['transports']['asset_pilot']['message_count']);
        self::assertStringContainsString('2 message(s)', $result->message);
    }

    #[Test]
    public function isCriticalWhenMessagesAreRoutedElsewhere(): void
    {
        $result = $this->check(
            $this->heartbeats(),
            1000,
            $this->receivers(['asset_pilot' => 0, 'pimcore_maintenance' => 0, 'asset_pilot_failed' => 0]),
            $this->senders('async'),
        )->run();

        self::assertSame(HealthStatus::Critical, $result->status);
        self::assertStringContainsString('not routed', $result->message);
    }

    #[Test]
    public function routingValidationIncludesTheDependencyProjectionRefreshRoute(): void
    {
        // M-05: the durable projection-repair route must be validated so an application cannot omit
        // it while the routing health check still reports healthy.
        $result = $this->check($this->heartbeats(), 1000)->run();

        self::assertArrayHasKey(
            DependencyProjectionRefreshMessage::class,
            $result->details['routing'],
            'the dependency-projection refresh route must be part of routing validation',
        );
    }

    private function check(
        ArrayAdapter $cache,
        int $now,
        ?ContainerInterface $receivers = null,
        ?SendersLocatorInterface $senders = null,
    ): AsyncTransportHealthCheck {
        return new class (true, 100, $cache, 120, $receivers ?? $this->receivers([
            'asset_pilot' => 0,
            'pimcore_maintenance' => 0,
            'asset_pilot_failed' => 0,
        ]), $senders ?? $this->senders('asset_pilot'), $now) extends AsyncTransportHealthCheck {
            public function __construct(
                bool $enabled,
                int $batchSize,
                ArrayAdapter $cache,
                int $maxAge,
                ContainerInterface $receivers,
                SendersLocatorInterface $senders,
                private int $currentTime,
            ) {
                parent::__construct($enabled, $batchSize, $cache, $maxAge, $receivers, $senders);
            }

            protected function now(): int
            {
                return $this->currentTime;
            }
        };
    }

    private function heartbeats(): ArrayAdapter
    {
        $cache = new ArrayAdapter();
        foreach (WorkerHeartbeatRecorder::REQUIRED_TRANSPORTS as $transportName) {
            $item = $cache->getItem(WorkerHeartbeatRecorder::cacheKey($transportName));
            $item->set(950);
            $cache->save($item);
        }

        return $cache;
    }

    /** @param array<string, int> $counts */
    private function receivers(array $counts): ContainerInterface
    {
        return new class ($counts) implements ContainerInterface {
            /** @param array<string, int> $counts */
            public function __construct(private array $counts) {}

            public function get(string $id): mixed
            {
                if (!$this->has($id)) {
                    throw new \RuntimeException('Unknown receiver.');
                }

                return new class ($this->counts[$id]) implements ReceiverInterface, MessageCountAwareInterface {
                    public function __construct(private int $count) {}

                    public function get(): iterable
                    {
                        return [];
                    }

                    public function ack(Envelope $envelope): void {}

                    public function reject(Envelope $envelope): void {}

                    public function getMessageCount(): int
                    {
                        return $this->count;
                    }
                };
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->counts);
            }
        };
    }

    private function senders(string $name): SendersLocatorInterface
    {
        return new class ($name) implements SendersLocatorInterface {
            public function __construct(private string $name) {}

            public function getSenders(Envelope $envelope): iterable
            {
                yield $this->name => new class () implements SenderInterface {
                    public function send(Envelope $envelope): Envelope
                    {
                        return $envelope;
                    }
                };
            }
        };
    }
}
