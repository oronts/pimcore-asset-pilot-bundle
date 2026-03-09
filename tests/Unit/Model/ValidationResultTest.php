<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Model;

use Oronts\AssetPilotBundle\Model\ValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationResult::class)]
class ValidationResultTest extends TestCase
{
    #[Test]
    public function constructsPassResult(): void
    {
        $result = new ValidationResult(
            ruleName: 'product_images',
            check: 'class_exists',
            status: 'pass',
            message: 'Class "Product" exists',
        );

        self::assertSame('product_images', $result->ruleName);
        self::assertSame('class_exists', $result->check);
        self::assertSame('pass', $result->status);
        self::assertSame('Class "Product" exists', $result->message);
    }

    #[Test]
    public function constructsFailResult(): void
    {
        $result = new ValidationResult(
            ruleName: 'bad_rule',
            check: 'class_exists',
            status: 'fail',
            message: 'Class "Missing" not found in Pimcore',
        );

        self::assertSame('fail', $result->status);
    }

    #[Test]
    public function constructsWarningResult(): void
    {
        $result = new ValidationResult(
            ruleName: 'warn_rule',
            check: 'duplicate_priority',
            status: 'warning',
            message: 'Rules a, b have same priority 10',
        );

        self::assertSame('warning', $result->status);
    }
}
