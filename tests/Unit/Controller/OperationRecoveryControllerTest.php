<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Controller;

use Oronts\AssetPilotBundle\Controller\Api\OperationRecoveryController;
use Oronts\AssetPilotBundle\Enum\ApplyPlanStatus;
use Oronts\AssetPilotBundle\Enum\OperationKind;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Exception\OperationRecoveryPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\OperationRecoveryResult;
use Oronts\AssetPilotBundle\Model\ReviewedOperationRecovery;
use Oronts\AssetPilotBundle\Security\ElementAuthorization;
use Oronts\AssetPilotBundle\Service\OperationRecoveryCoordinatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(OperationRecoveryController::class)]
final class OperationRecoveryControllerTest extends TestCase
{
    #[Test]
    public function previewsForTheAuthenticatedActor(): void
    {
        $actor = ActorContext::user(17);
        $coordinator = $this->createMock(OperationRecoveryCoordinatorInterface::class);
        $coordinator->expects(self::once())->method('preview')->with(25, $actor)->willReturn(
            new ReviewedOperationRecovery([$this->recoveryResult(OperationStatus::Completed, false)], 'signed', false),
        );

        $response = $this->controller($coordinator, $actor)->recover($this->json(['limit' => 25]));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertFalse($body['applied']);
        self::assertSame('signed', $body['planToken']);
        self::assertSame('completed', $body['results'][0]['classification']);
    }

    #[Test]
    public function appliesOnlyWithTheReviewedToken(): void
    {
        $actor = ActorContext::user(17);
        $coordinator = $this->createMock(OperationRecoveryCoordinatorInterface::class);
        $coordinator->expects(self::once())->method('apply')->with(100, $actor, 'signed')->willReturn(
            new ReviewedOperationRecovery([$this->recoveryResult(OperationStatus::Completed, true)], null, true),
        );

        $response = $this->controller($coordinator, $actor)->recover($this->json([
            'apply' => true,
            'planToken' => 'signed',
        ]));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($body['applied']);
        self::assertSame(0, $body['unresolved']);
        self::assertTrue($body['results'][0]['journalUpdated']);
    }

    #[Test]
    public function rejectsInvalidInputBeforeCallingRecovery(): void
    {
        $coordinator = $this->createMock(OperationRecoveryCoordinatorInterface::class);
        $coordinator->expects(self::never())->method('preview');
        $coordinator->expects(self::never())->method('apply');

        $response = $this->controller($coordinator, ActorContext::user(17))->recover($this->json([
            'limit' => '100',
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function mapsMalformedAndStalePlansToDifferentStatuses(): void
    {
        $actor = ActorContext::user(17);
        $malformed = $this->createMock(OperationRecoveryCoordinatorInterface::class);
        $malformed->method('apply')->willThrowException(new OperationRecoveryPlanException(ApplyPlanStatus::Malformed));
        $stale = $this->createMock(OperationRecoveryCoordinatorInterface::class);
        $stale->method('apply')->willThrowException(new OperationRecoveryPlanException(ApplyPlanStatus::Stale));
        $request = fn (): Request => $this->json(['apply' => true, 'planToken' => 'token']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->controller($malformed, $actor)->recover($request())->getStatusCode());
        self::assertSame(Response::HTTP_CONFLICT, $this->controller($stale, $actor)->recover($request())->getStatusCode());
    }

    private function controller(
        OperationRecoveryCoordinatorInterface $coordinator,
        ActorContext $actor,
    ): OperationRecoveryController {
        $authorization = $this->createMock(ElementAuthorization::class);
        $authorization->method('currentActor')->willReturn($actor);

        return new OperationRecoveryController($coordinator, $authorization, new NullLogger());
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): Request
    {
        return Request::create('/', 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function recoveryResult(OperationStatus $status, bool $updated): OperationRecoveryResult
    {
        return new OperationRecoveryResult(91, 7, OperationKind::Move, $status, $updated, 'classification', str_repeat('a', 64));
    }
}
