<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller\Api\Support;

use Oronts\AssetPilotBundle\Controller\Api\Support\ReadsRequestScalars;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

#[CoversTrait(ReadsRequestScalars::class)]
class ReadsRequestScalarsTest extends TestCase
{
    private object $reader;

    protected function setUp(): void
    {
        $this->reader = new class () {
            use ReadsRequestScalars;

            public function bool(array $data, string $key, bool $default): bool|JsonResponse
            {
                return $this->requestBool($data, $key, $default);
            }

            public function optInt(array $data, string $key, ?int $max, ?int $default): int|JsonResponse|null
            {
                return $this->requestOptionalPositiveInt($data, $key, $max, $default);
            }

            public function reqInt(array $data, string $key, ?int $max): int|JsonResponse
            {
                return $this->requestPositiveInt($data, $key, $max);
            }

            public function str(array $data, string $key, ?string $default): string|JsonResponse
            {
                return $this->requestString($data, $key, $default);
            }

            public function optStr(array $data, string $key): string|JsonResponse|null
            {
                return $this->requestOptionalString($data, $key);
            }
        };
    }

    #[Test]
    public function requestBoolAcceptsOnlyNativeBooleans(): void
    {
        self::assertTrue($this->reader->bool(['async' => true], 'async', false));
        self::assertFalse($this->reader->bool(['async' => false], 'async', true));
        self::assertFalse($this->reader->bool([], 'async', false));

        foreach (['false', 'true', '0', 1, 0, 'no'] as $bad) {
            $result = $this->reader->bool(['async' => $bad], 'async', false);
            self::assertInstanceOf(JsonResponse::class, $result, 'must reject ' . var_export($bad, true));
            self::assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
        }
    }

    #[Test]
    public function positiveIntReadersRejectMalformedZeroNegativeAndOverMax(): void
    {
        self::assertSame(7, $this->reader->reqInt(['objectId' => 7], 'objectId', null));
        self::assertSame(7, $this->reader->reqInt(['objectId' => '7'], 'objectId', null));
        self::assertSame(50, $this->reader->optInt([], 'limit', 200, 50));
        self::assertNull($this->reader->optInt([], 'limit', 200, null));

        foreach (['not-an-id', '-5', '1.5', 1.5, true, 0, '0', -3, '007', str_repeat('9', 30)] as $bad) {
            $result = $this->reader->reqInt(['objectId' => $bad], 'objectId', null);
            self::assertInstanceOf(JsonResponse::class, $result, 'must reject ' . var_export($bad, true));
            self::assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
        }

        $overMax = $this->reader->optInt(['limit' => 5000], 'limit', 1000, null);
        self::assertInstanceOf(JsonResponse::class, $overMax);
        self::assertSame(Response::HTTP_BAD_REQUEST, $overMax->getStatusCode());
    }

    #[Test]
    public function requiredPositiveIntRejectsAMissingValue(): void
    {
        $result = $this->reader->reqInt([], 'objectId', null);
        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
    }

    #[Test]
    public function stringReadersRejectNonStringsAndHandleAbsence(): void
    {
        self::assertSame('Product', $this->reader->str(['className' => 'Product'], 'className', null));
        self::assertSame('def', $this->reader->str([], 'className', 'def'));
        self::assertNull($this->reader->optStr([], 'className'));
        self::assertSame('Product', $this->reader->optStr(['className' => 'Product'], 'className'));

        self::assertInstanceOf(JsonResponse::class, $this->reader->str([], 'className', null));

        foreach ([123, true, ['x'], 1.5] as $bad) {
            self::assertInstanceOf(JsonResponse::class, $this->reader->str(['className' => $bad], 'className', null), 'str must reject ' . var_export($bad, true));
            self::assertInstanceOf(JsonResponse::class, $this->reader->optStr(['className' => $bad], 'className'), 'optStr must reject ' . var_export($bad, true));
        }
    }
}
