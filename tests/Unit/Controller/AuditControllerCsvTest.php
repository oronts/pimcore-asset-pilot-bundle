<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Api\Serialization\ApiDateFormatterInterface;
use Oronts\AssetPilotBundle\Audit\AuditExportInterface;
use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Controller\Api\AuditController;
use Oronts\AssetPilotBundle\Service\OperationReverterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AuditController::class)]
class AuditControllerCsvTest extends TestCase
{
    private function controller(): object
    {
        return new class ($this->createMock(AuditQueryInterface::class), $this->createMock(AuditExportInterface::class), new NullLogger(), $this->createMock(OperationReverterInterface::class), $this->createMock(ApiDateFormatterInterface::class)) extends AuditController {
            public function sanitize(mixed $v): string
            {
                return $this->sanitizeCsvCell($v);
            }
        };
    }

    #[Test]
    #[DataProvider('formulaCells')]
    public function prefixesFormulaLeadingCells(string $input): void
    {
        self::assertSame("'" . $input, $this->controller()->sanitize($input));
    }

    public static function formulaCells(): array
    {
        return [
            ['=1+1'],
            ['+1'],
            ['-1'],
            ['@SUM(A1)'],
            ['  =1+1'],
            [chr(9) . '  @SUM(A1)'],
            [chr(9) . 'x'],
            [chr(13) . 'x'],
            [chr(10) . 'x'],
        ];
    }

    #[Test]
    public function leavesOrdinaryValuesUntouched(): void
    {
        $c = $this->controller();
        self::assertSame('/Products/a.jpg', $c->sanitize('/Products/a.jpg'));
        self::assertSame('  ordinary', $c->sanitize('  ordinary'));
        self::assertSame('42', $c->sanitize(42));
        self::assertSame('', $c->sanitize(''));
    }
}
