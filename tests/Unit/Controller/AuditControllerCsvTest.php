<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Controller\Api\AuditController;
use Oronts\AssetPilotBundle\Service\LoopGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(AuditController::class)]
class AuditControllerCsvTest extends TestCase
{
    private function controller(): object
    {
        return new class($this->createMock(AuditLogger::class), new NullLogger(), $this->createMock(LoopGuard::class), new EventDispatcher()) extends AuditController {
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
        return [['=1+1'], ['+1'], ['-1'], ['@SUM(A1)'], ["\tx"], ["\rx"], ["\nx"]];
    }

    #[Test]
    public function leavesOrdinaryValuesUntouched(): void
    {
        $c = $this->controller();
        self::assertSame('/Products/a.jpg', $c->sanitize('/Products/a.jpg'));
        self::assertSame('42', $c->sanitize(42));
        self::assertSame('', $c->sanitize(''));
    }
}
