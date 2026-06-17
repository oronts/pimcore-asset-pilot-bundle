<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Naming;

use Oronts\AssetPilotBundle\Enum\CollisionPattern;
use Oronts\AssetPilotBundle\Naming\SafeNamingStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SafeNamingStrategy::class)]
class SafeNamingStrategyTest extends TestCase
{
    /** @param callable(string): bool $exists */
    private function strategy(callable $exists): object
    {
        $strategy = new class(new NullLogger()) extends SafeNamingStrategy {
            /** @var callable(string): bool */
            public $exists;

            protected function pathExists(string $fullPath): bool
            {
                return ($this->exists)($fullPath);
            }

            public function counter(string $basename, string $extension, string $targetPath): string
            {
                return $this->resolveWithCounter($basename, $extension, $targetPath);
            }

            public function timestamp(string $basename, string $extension, string $targetPath): string
            {
                return $this->resolveWithTimestamp($basename, $extension, $targetPath);
            }

            public function uuid(string $basename, string $extension, string $targetPath): string
            {
                return $this->resolveWithUuid($basename, $extension, $targetPath);
            }
        };
        $strategy->exists = $exists;

        return $strategy;
    }

    #[Test]
    public function counterReturnsTheFirstFreeIndex(): void
    {
        $taken = ['/p/img_1.jpg', '/p/img_2.jpg'];
        $name = $this->strategy(static fn (string $p): bool => in_array($p, $taken, true))->counter('img', 'jpg', '/p');

        self::assertSame('img_3.jpg', $name);
    }

    #[Test]
    public function counterFallsBackToUuidWhenExhausted(): void
    {
        // Every numbered candidate is taken; the counter must not loop forever, it falls back to uuid.
        $name = $this->strategy(static fn (string $p): bool => (bool) preg_match('/_\d+\.jpg$/', $p))->counter('img', 'jpg', '/p');

        self::assertMatchesRegularExpression('/^img_[0-9a-f]{16}\.jpg$/', $name);
    }

    #[Test]
    public function timestampAddsEntropyWhenTheBareTimestampCollides(): void
    {
        // The plain "img_<timestamp>.jpg" is taken; a same-second move must still get a free name.
        $name = $this->strategy(static fn (string $p): bool => (bool) preg_match('#/img_\d+\.jpg$#', $p))->timestamp('img', 'jpg', '/p');

        self::assertMatchesRegularExpression('/^img_\d+_[0-9a-f]{4}\.jpg$/', $name);
    }

    #[Test]
    public function uuidRetriesUntilItFindsAFreeName(): void
    {
        $calls = 0;
        $name = $this->strategy(static function (string $p) use (&$calls): bool {
            return ++$calls === 1; // first generated uuid name is taken, second is free
        })->uuid('img', 'jpg', '/p');

        self::assertMatchesRegularExpression('/^img_[0-9a-f]{16}\.jpg$/', $name);
        self::assertSame(2, $calls);
    }

    #[Test]
    public function extensionlessFilesGetNoTrailingDot(): void
    {
        $name = $this->strategy(static fn (): bool => false)->counter('README', '', '/docs');

        self::assertSame('README_1', $name);
    }

    #[Test]
    public function collisionPatternEnumValuesAreTheSupportedModes(): void
    {
        self::assertSame(['counter', 'timestamp', 'uuid'], CollisionPattern::values());
    }
}
