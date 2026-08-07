<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\Support\DecodesJsonObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DecodesJsonObjectTest extends TestCase
{
    private object $decoder;

    protected function setUp(): void
    {
        $this->decoder = new class () {
            use DecodesJsonObject;

            public function decode(Request $request, bool $allowEmpty = false): array|JsonResponse
            {
                return $this->decodeJsonObject($request, $allowEmpty);
            }
        };
    }

    #[Test]
    #[DataProvider('nonObjectBodies')]
    public function rejectsValidJsonWhoseRootIsNotAnObject(string $body): void
    {
        $result = $this->decoder->decode(Request::create('/', 'POST', [], [], [], [], $body));

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(Response::HTTP_BAD_REQUEST, $result->getStatusCode());
        self::assertStringContainsString('JSON object', (string) $result->getContent());
    }

    public static function nonObjectBodies(): iterable
    {
        yield 'null' => ['null'];
        yield 'number' => ['42'];
        yield 'string' => ['"value"'];
        yield 'list' => ['[1, 2]'];
    }

    #[Test]
    public function acceptsAnEmptyJsonObject(): void
    {
        self::assertSame([], $this->decoder->decode(Request::create('/', 'POST', [], [], [], [], '{}')));
    }

    #[Test]
    public function optionalBodyTreatsEmptyContentAsAnEmptyObject(): void
    {
        self::assertSame([], $this->decoder->decode(Request::create('/', 'POST'), true));
    }
}
