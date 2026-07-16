<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesReviewedPlanRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class DecodesReviewedPlanRequestTest extends TestCase
{
    private object $decoder;

    protected function setUp(): void
    {
        $this->decoder = new class () {
            use DecodesReviewedPlanRequest;

            public function decode(Request $request): array|JsonResponse
            {
                return $this->decodeReviewedPlanRequest($request);
            }
        };
    }

    #[Test]
    public function appliesDefaultsForAValidPreview(): void
    {
        self::assertSame(
            ['limit' => 100, 'apply' => false, 'planToken' => null],
            $this->decoder->decode(Request::create('/', 'POST', [], [], [], [], '{}')),
        );
    }

    #[Test]
    public function acceptsAValidApplyRequest(): void
    {
        self::assertSame(
            ['limit' => 25, 'apply' => true, 'planToken' => 'signed'],
            $this->decoder->decode(Request::create('/', 'POST', [], [], [], [], '{"limit":25,"apply":true,"planToken":"signed"}')),
        );
    }

    #[Test]
    #[DataProvider('invalidBodies')]
    public function rejectsInvalidReviewedPlanInput(string $body, string $message): void
    {
        $response = $this->decoder->decode(Request::create('/', 'POST', [], [], [], [], $body));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(JsonResponse::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString($message, (string) $response->getContent());
    }

    public static function invalidBodies(): iterable
    {
        yield 'limit type' => ['{"limit":"25"}', 'limit'];
        yield 'limit too low' => ['{"limit":0}', 'limit'];
        yield 'limit too high' => ['{"limit":1001}', 'limit'];
        yield 'apply type' => ['{"apply":1}', 'apply'];
        yield 'missing apply token' => ['{"apply":true}', 'planToken'];
        yield 'empty apply token' => ['{"apply":true,"planToken":""}', 'planToken'];
        yield 'preview token' => ['{"planToken":"signed"}', 'planToken'];
    }
}
