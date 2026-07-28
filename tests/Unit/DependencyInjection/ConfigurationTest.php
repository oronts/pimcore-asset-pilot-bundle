<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\DependencyInjection;

use Oronts\AssetPilotBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
class ConfigurationTest extends TestCase
{
    /** @param array<string, mixed> $rule */
    private function processRule(array $rule): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [
            ['rules' => ['my_rule' => ['class' => 'Product', 'target_path' => '/x'] + $rule]],
        ]);
    }

    #[Test]
    public function ruleAcceptsFreeFormArrayOptions(): void
    {
        $config = $this->processRule(['options' => ['threshold' => 5, 'mode' => 'strict']]);

        self::assertSame(['threshold' => 5, 'mode' => 'strict'], $config['rules']['my_rule']['options']);
    }

    #[Test]
    public function ruleOptionsDefaultsToAnEmptyArray(): void
    {
        $config = $this->processRule([]);

        self::assertSame([], $config['rules']['my_rule']['options']);
    }

    #[Test]
    public function ruleOptionsRejectsAScalar(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processRule(['options' => 'strict']);
    }

    #[Test]
    public function callbackStrategyRequiresACallbackService(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processRule(['strategy' => 'callback']);
    }

    #[Test]
    public function allowedClassesDefaultsToAnEmptyArray(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame([], $config['allowed_classes']);
        self::assertSame([], $config['locales']);
        self::assertSame(50, $config['async']['batch_size']);
        self::assertSame(120, $config['async']['worker_heartbeat_max_age']);
        self::assertSame('asset_pilot', $config['async']['transport']);
        self::assertSame('asset_pilot_failed', $config['async']['failure_transport']);
        self::assertSame(1000, $config['async']['max_queue_depth']);
        self::assertSame(60.0, $config['idempotency']['lock_ttl']);
        self::assertSame(3, $config['idempotency']['max_object_replays']);
        self::assertSame(900, $config['operation_journal']['recovery_after_seconds']);
        self::assertSame(100, $config['operation_journal']['delivery_batch_size']);
        self::assertSame(3600, $config['operation_journal']['dispatch_deduplication_seconds']);
        self::assertSame(5, $config['operation_journal']['max_attempts']);
        self::assertSame(30, $config['operation_journal']['base_retry_seconds']);
        self::assertSame(3600, $config['operation_journal']['max_retry_seconds']);
        self::assertSame(300, $config['operation_journal']['lease_seconds']);
        self::assertTrue($config['dependency_projection']['bootstrap_live_scan']);
        self::assertSame(50000, $config['dependency_projection']['bootstrap_max_sources']);
        self::assertSame(1000, $config['dependency_projection']['rebuild_batch_size']);
        self::assertSame(536870912, $config['zip']['max_uncompressed_bytes']);
        self::assertSame(300, $config['zip']['download_token_ttl']);
        self::assertFalse($config['content_scan']['enabled']);
    }

    #[Test]
    public function idempotencyLockTtlMustBePositive(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['idempotency' => ['lock_ttl' => 0]],
        ]);
    }

    #[Test]
    public function objectReplayLimitMustBePositive(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['idempotency' => ['max_object_replays' => 0]],
        ]);
    }

    #[Test]
    public function operationJournalRetryWindowMustBeCoherent(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['operation_journal' => ['base_retry_seconds' => 60, 'max_retry_seconds' => 30]],
        ]);
    }

    #[Test]
    public function workerHeartbeatMaximumAgeMustAllowAWorkerLoop(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['async' => ['worker_heartbeat_max_age' => 1]],
        ]);
    }

    #[Test]
    public function removedDeadNodesAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['strategies' => ['default' => 'always']],
        ]);
    }

    #[Test]
    public function removedContentScanClassAllowlistIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['content_scan' => ['classes' => ['Product']]],
        ]);
    }

    #[Test]
    public function removedContentScanSourceBudgetIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['content_scan' => ['max_sources' => 50000]],
        ]);
    }

    #[Test]
    public function dependencyProjectionBatchSizeIsBounded(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['dependency_projection' => ['rebuild_batch_size' => 10001]],
        ]);
    }

    #[Test]
    public function removedAuditDisableSwitchIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['audit' => ['enabled' => false]],
        ]);
    }

    #[Test]
    public function ruleAcceptsActionsAndKeepsTheirArbitraryKeys(): void
    {
        $config = $this->processRule(['actions' => [['type' => 'set_property', 'name' => 'cdn_ready', 'value' => 'yes']]]);

        $action = $config['rules']['my_rule']['actions'][0];
        self::assertSame('set_property', $action['type']);
        self::assertSame('cdn_ready', $action['name']);
        self::assertSame('yes', $action['value']);
    }

    #[Test]
    public function ruleActionRequiresAType(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processRule(['actions' => [['name' => 'cdn_ready']]]);
    }

    #[Test]
    public function confidenceThresholdsDefaultToThirtyAndNinety(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame(30, $config['confidence']['recently_uploaded_days']);
        self::assertSame(90, $config['confidence']['probably_unused_days']);
    }

    #[Test]
    public function confidenceRejectsAProbablyUnusedWindowThatCollapsesTheMiddleBucket(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [
            ['confidence' => ['recently_uploaded_days' => 30, 'probably_unused_days' => 30]],
        ]);
    }
}
