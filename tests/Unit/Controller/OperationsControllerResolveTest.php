<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Controller\Api\OperationsController;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractor;
use Oronts\AssetPilotBundle\Service\AssetOrganizer;
use Oronts\AssetPilotBundle\Service\AssetReorganizer;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
use Oronts\AssetPilotBundle\Service\OrganizeDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(OperationsController::class)]
class OperationsControllerResolveTest extends TestCase
{
    private function controller(?AbstractObject $object): object
    {
        $c = new class (
            $this->createMock(AssetOrganizer::class),
            $this->createMock(OrganizeDispatcher::class),
            $this->createMock(AuditLogger::class),
            $this->createMock(RuleEngine::class),
            $this->createMock(AssetFieldExtractor::class),
            $this->createMock(FailureReplayService::class),
            $this->createMock(AssetReorganizer::class),
            new NullLogger(),
        ) extends OperationsController {
            public ?AbstractObject $stub = null;

            protected function loadObject(int $id): ?AbstractObject
            {
                return $this->stub;
            }

            public function resolve(Request $request): array|JsonResponse
            {
                return $this->resolveObjectFromBody($request);
            }
        };
        $c->stub = $object;

        return $c;
    }

    private function post(string $body): Request
    {
        return Request::create('/', 'POST', [], [], [], [], $body);
    }

    #[Test]
    public function rejectsInvalidJson(): void
    {
        $response = $this->controller(null)->resolve($this->post('{not json'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function rejectsScalarJsonBody(): void
    {
        $response = $this->controller(null)->resolve($this->post('123'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function rejectsMissingObjectId(): void
    {
        $response = $this->controller(null)->resolve($this->post('{"foo":1}'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('objectId', (string) $response->getContent());
    }

    #[Test]
    public function returnsNotFoundWhenObjectMissing(): void
    {
        $response = $this->controller(null)->resolve($this->post('{"objectId":42}'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    #[Test]
    public function returnsObjectAndBodyOnSuccess(): void
    {
        $object = $this->createMock(AbstractObject::class);
        $response = $this->controller($object)->resolve($this->post('{"objectId":42,"dryRun":true}'));

        self::assertIsArray($response);
        self::assertSame($object, $response[0]);
        self::assertTrue($response[1]['dryRun']);
    }
}
