<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller\Api\Support;

use Oronts\AssetPilotBundle\Controller\Api\Support\StreamsCsv;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversTrait(StreamsCsv::class)]
final class StreamsCsvTest extends TestCase
{
    private function subject(): object
    {
        return new class () {
            use StreamsCsv;

            /**
             * @param list<string>                $header
             * @param iterable<array<int, mixed>> $rows
             */
            public function render(array $header, iterable $rows): string
            {
                $response = $this->streamCsv('export.csv', $header, $rows);
                ob_start();
                $response->sendContent();

                return (string) ob_get_clean();
            }
        };
    }

    #[Test]
    public function appendsATruncationMarkerRowWhenTheSourceGeneratorReturnsTrue(): void
    {
        $rows = (static function (): \Generator {
            yield [1, 'alpha'];
            yield [2, 'beta'];

            return true;
        })();

        $csv = $this->subject()->render(['ID', 'Name'], $rows);

        self::assertStringContainsString('alpha', $csv);
        self::assertStringContainsString('TRUNCATED', $csv);
        self::assertStringContainsString('after 2 rows', $csv, 'the marker names the row count actually emitted');
    }

    #[Test]
    public function omitsTheMarkerWhenTheSourceGeneratorReturnsFalse(): void
    {
        $rows = (static function (): \Generator {
            yield [1, 'alpha'];

            return false;
        })();

        $csv = $this->subject()->render(['ID', 'Name'], $rows);

        self::assertStringContainsString('alpha', $csv);
        self::assertStringNotContainsString('TRUNCATED', $csv);
    }

    #[Test]
    public function omitsTheMarkerForANonGeneratorSource(): void
    {
        $csv = $this->subject()->render(['ID'], [[1], [2]]);

        self::assertStringNotContainsString('TRUNCATED', $csv);
    }
}
