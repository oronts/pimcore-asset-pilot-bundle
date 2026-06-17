<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Oronts\AssetPilotBundle\Enum\ConfidenceLevel;
use Oronts\AssetPilotBundle\Service\ConfidenceScorer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ConfidenceScorer::class)]
class ConfidenceScorerTest extends TestCase
{
    public const string NOW = '2026-06-17 12:00:00';

    private function scorer(): object
    {
        return new class ($this->createMock(Connection::class), new NullLogger()) extends ConfidenceScorer {
            public bool $historyThrows = false;
            /** @var int[] */
            public array $history = [];

            public function classifyExposed(array $item): string
            {
                return $this->classify($item, $this->history, new \DateTimeImmutable(ConfidenceScorerTest::NOW));
            }

            protected function getAssetsWithAuditHistory(array $assetIds): array
            {
                if ($this->historyThrows) {
                    throw new \RuntimeException('audit table unavailable');
                }

                return $this->history;
            }
        };
    }

    #[Test]
    public function lockedAssetsAreProtected(): void
    {
        self::assertSame(ConfidenceLevel::Protected->value, $this->scorer()->classifyExposed(['id' => 1, 'locked' => true]));
    }

    #[Test]
    public function auditedAssetsAreHistoricallyUsed(): void
    {
        $scorer = $this->scorer();
        $scorer->history = [7];

        self::assertSame(ConfidenceLevel::HistoricallyUsed->value, $scorer->classifyExposed(['id' => 7, 'locked' => false, 'modified_at' => '2020-01-01 00:00:00']));
    }

    #[Test]
    public function aFutureModificationDateIsTreatedAsRecentNotAged(): void
    {
        self::assertSame(
            ConfidenceLevel::RecentlyUploaded->value,
            $this->scorer()->classifyExposed(['id' => 1, 'locked' => false, 'modified_at' => '2027-01-01 00:00:00']),
        );
    }

    #[Test]
    public function ageBucketsMapToTheRightLevel(): void
    {
        $scorer = $this->scorer();

        self::assertSame(ConfidenceLevel::RecentlyUploaded->value, $scorer->classifyExposed(['id' => 1, 'modified_at' => '2026-06-10 12:00:00']));
        self::assertSame(ConfidenceLevel::ProbablyUnused->value, $scorer->classifyExposed(['id' => 1, 'modified_at' => '2026-04-10 12:00:00']));
        self::assertSame(ConfidenceLevel::DefinitelyUnused->value, $scorer->classifyExposed(['id' => 1, 'modified_at' => '2026-01-01 12:00:00']));
    }

    #[Test]
    public function scoreFailsClosedWhenAuditHistoryIsUnreadable(): void
    {
        $scorer = $this->scorer();
        $scorer->historyThrows = true;

        $result = $scorer->score([
            ['id' => 1, 'modified_at' => '2020-01-01 00:00:00'],
            ['id' => 2, 'modified_at' => '2019-01-01 00:00:00'],
        ]);

        // Both would be definitely_unused by age, but a failed history lookup must not risk that.
        self::assertSame(ConfidenceLevel::HistoricallyUsed->value, $result[0]['confidence']);
        self::assertSame(ConfidenceLevel::HistoricallyUsed->value, $result[1]['confidence']);
    }
}
