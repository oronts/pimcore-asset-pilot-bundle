<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Command\Support;

use Oronts\AssetPilotBundle\Command\Support\ValidatesCliBulkIds;
use Oronts\AssetPilotBundle\Support\BulkIds;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversTrait(ValidatesCliBulkIds::class)]
class ValidatesCliBulkIdsTest extends TestCase
{
    private function subject(): object
    {
        return new class () {
            use ValidatesCliBulkIds;

            /** @return list<int>|null */
            public function run(SymfonyStyle $io, ?string $csv): ?array
            {
                return $this->validatedCsvIds($io, $csv, '--ids');
            }
        };
    }

    private function io(BufferedOutput $out): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $out);
    }

    public function testValidCsvReturnsCleanTrimmedList(): void
    {
        $out = new BufferedOutput();
        self::assertSame([1, 2, 3], $this->subject()->run($this->io($out), '1, 2 , 3'));
    }

    public function testEmptyOrGarbageReturnsNullAndPrintsError(): void
    {
        $out = new BufferedOutput();
        self::assertNull($this->subject()->run($this->io($out), 'abc,,-1,0'));
        self::assertStringContainsString('must list one or more positive ids', $out->fetch());
    }

    public function testNullReturnsNull(): void
    {
        self::assertNull($this->subject()->run($this->io(new BufferedOutput()), null));
    }

    public function testOverCapReturnsNullAndPrintsError(): void
    {
        $out = new BufferedOutput();
        $csv = implode(',', range(1, BulkIds::MAX + 5));
        self::assertNull($this->subject()->run($this->io($out), $csv));
        self::assertStringContainsString('at most', $out->fetch());
    }
}
