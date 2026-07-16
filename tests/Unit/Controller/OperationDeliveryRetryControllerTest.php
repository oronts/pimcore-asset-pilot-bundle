<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\OperationDeliveryRetryController;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\AssetPilotPermission;
use Oronts\AssetPilotBundle\Enum\OperationDeliveryOutcome;
use Oronts\AssetPilotBundle\Exception\DeliveryRetryPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DeadOperationDelivery;
use Oronts\AssetPilotBundle\Model\ReviewedDeliveryRetry;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\OperationDeliveryRetryCoordinatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[CoversClass(OperationDeliveryRetryController::class)]
final class OperationDeliveryRetryControllerTest extends TestCase
{
    #[Test]
    public function previewUsesTheAuthenticatedActorAndReturnsTheSignedScope(): void
    {
        $actor = ActorContext::user(17);
        $coordinator = $this->createMock(OperationDeliveryRetryCoordinatorInterface::class);
        $coordinator->expects(self::once())->method('preview')->with(25, $actor)->willReturn(
            new ReviewedDeliveryRetry([$this->delivery()], 'signed', false),
        );

        $response = $this->controller($coordinator, $actor)->retry($this->json(['limit' => 25]));
        $body = $this->body($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertFalse($body['applied']);
        self::assertSame('signed', $body['planToken']);
        self::assertSame(1, $body['count']);
        self::assertSame(str_repeat('d', 64), $body['deliveries'][0]['deliveryId']);
        self::assertSame(str_repeat('f', 64), $body['deliveries'][0]['fingerprint']);
        self::assertSame('2026-07-15T10:00:00+00:00', $body['deliveries'][0]['updatedAt']);
    }

    #[Test]
    public function applyUsesOnlyTheMatchingActorLimitAndToken(): void
    {
        $actor = ActorContext::user(17);
        $coordinator = $this->createMock(OperationDeliveryRetryCoordinatorInterface::class);
        $coordinator->expects(self::once())->method('apply')->with(100, $actor, 'signed')->willReturn(
            new ReviewedDeliveryRetry([$this->delivery()], null, true),
        );

        $response = $this->controller($coordinator, $actor)->retry($this->json([
            'apply' => true,
            'planToken' => 'signed',
        ]));
        $body = $this->body($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($body['applied']);
        self::assertNull($body['planToken']);
        self::assertSame(1, $body['count']);
    }

    #[Test]
    public function invalidRequestsAreRejectedBeforeReviewOrApply(): void
    {
        foreach ([
            ['limit' => '100'],
            ['limit' => 0],
            ['limit' => 1_001],
            ['apply' => 'true'],
            ['apply' => true],
            ['planToken' => 'preview-token'],
        ] as $body) {
            $coordinator = $this->createMock(OperationDeliveryRetryCoordinatorInterface::class);
            $coordinator->expects(self::never())->method('preview');
            $coordinator->expects(self::never())->method('apply');

            $response = $this->controller($coordinator, ActorContext::user(17))->retry($this->json($body));

            self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        }
    }

    #[Test]
    public function malformedPlanIsBadRequestAndStalePlanIsConflict(): void
    {
        $actor = ActorContext::user(17);
        $malformed = $this->createMock(OperationDeliveryRetryCoordinatorInterface::class);
        $malformed->method('apply')->willThrowException(new DeliveryRetryPlanException(ApplyPlanStatus::Malformed));
        $stale = $this->createMock(OperationDeliveryRetryCoordinatorInterface::class);
        $stale->method('apply')->willThrowException(new DeliveryRetryPlanException(ApplyPlanStatus::Stale));
        $request = fn (): Request => $this->json(['apply' => true, 'planToken' => 'token']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->controller($malformed, $actor)->retry($request())->getStatusCode());
        self::assertSame(Response::HTTP_CONFLICT, $this->controller($stale, $actor)->retry($request())->getStatusCode());
    }

    #[Test]
    public function routeRequiresTheAdminPermission(): void
    {
        $method = new \ReflectionMethod(OperationDeliveryRetryController::class, 'retry');
        $attribute = $method->getAttributes(IsGranted::class)[0]->newInstance();

        self::assertSame(AssetPilotPermission::Admin->value, $attribute->attribute);
    }

    private function controller(
        OperationDeliveryRetryCoordinatorInterface $coordinator,
        ActorContext $actor,
    ): OperationDeliveryRetryController {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);

        return new OperationDeliveryRetryController($coordinator, $authorization, new NullLogger());
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): Request
    {
        return Request::create('/', 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function delivery(): DeadOperationDelivery
    {
        return new DeadOperationDelivery(
            str_repeat('d', 64),
            91,
            'observer:success',
            'observer',
            OperationDeliveryOutcome::Success,
            5,
            'unavailable',
            '2026-07-15 10:00:00',
            str_repeat('f', 64),
        );
    }
}
