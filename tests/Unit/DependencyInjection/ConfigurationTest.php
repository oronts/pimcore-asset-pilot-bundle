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
