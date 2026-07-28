<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Tests\Unit\Api\Serialization;

use Oronts\AssetPilotBundle\Api\Serialization\OperationResponseAssembler;
use Oronts\AssetPilotBundle\Enum\BulkObjectStatus;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Oronts\AssetPilotBundle\Enum\TriggerType;
use Oronts\AssetPilotBundle\Model\BulkObjectResult;
use Oronts\AssetPilotBundle\Model\BulkOrganizeReport;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\OperationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(OperationResponseAssembler::class)]
final class OperationResponseAssemblerTest extends TestCase
{
    private function assembler(): OperationResponseAssembler
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters): string => '/runs/' . $parameters['id'],
        );

        return new OperationResponseAssembler($urls);
    }

    private function move(int $assetId, string $rule = 'rule-x'): MoveOperation
    {
        return new MoveOperation(
            assetId: $assetId,
            sourcePath: '/src/' . $assetId,
            targetPath: '/dst/' . $assetId,
            objectId: 100 + $assetId,
            objectClass: 'Product',
            ruleName: $rule,
            status: OperationStatus::Completed,
            triggerType: TriggerType::Api,
        );
    }

    #[Test]
    public function previewOperationEmitsTheFullSevenFieldPreviewShape(): void
    {
        $operation = $this->move(7);

        self::assertSame([
            'assetId' => 7,
            'sourcePath' => '/src/7',
            'targetPath' => '/dst/7',
            'ruleName' => 'rule-x',
            'objectId' => 107,
            'objectClass' => 'Product',
            'status' => 'completed',
        ], $this->assembler()->previewOperation($operation));
    }

    #[Test]
    public function singleRunResultEmitsTheRunIdAndTheFourFieldOperationPerResult(): void
    {
        $result = OperationResult::success($this->move(7));

        $assembled = $this->assembler()->singleRunResult('run-1', [$result]);

        self::assertSame('run-1', $assembled['runId']);
        self::assertCount(1, $assembled['results']);
        self::assertSame($result->status->value, $assembled['results'][0]['status']);
        self::assertSame($result->message, $assembled['results'][0]['message']);
        self::assertSame(
            ['assetId' => 7, 'sourcePath' => '/src/7', 'targetPath' => '/dst/7', 'ruleName' => 'rule-x'],
            $assembled['results'][0]['operation'],
            'the run-result operation shape is the four-field summary, not the seven-field preview',
        );
    }

    #[Test]
    public function bulkRunResultEmitsCountsObjectResultsAndObserverWarnings(): void
    {
        $report = new BulkOrganizeReport(
            results: [OperationResult::success($this->move(7))],
            objectResults: [
                new BulkObjectResult(107, BulkObjectStatus::Succeeded, null, 1),
                new BulkObjectResult(108, BulkObjectStatus::Skipped, 'already organized', 0),
                new BulkObjectResult(109, BulkObjectStatus::Failed, 'boom', 0),
            ],
            observerWarnings: ['a warning'],
        );

        $assembled = $this->assembler()->bulkRunResult('run-2', $report);

        self::assertSame('run-2', $assembled['runId']);
        self::assertSame(3, $assembled['objectCount']);
        self::assertSame(1, $assembled['resultCount']);
        self::assertSame(['attempted' => 3, 'succeeded' => 1, 'skipped' => 1, 'failed' => 1], $assembled['objectCounts']);
        self::assertSame([
            ['objectId' => 107, 'status' => 'succeeded', 'reason' => null, 'operationCount' => 1],
            ['objectId' => 108, 'status' => 'skipped', 'reason' => 'already organized', 'operationCount' => 0],
            ['objectId' => 109, 'status' => 'failed', 'reason' => 'boom', 'operationCount' => 0],
        ], $assembled['objectResults']);
        self::assertSame(['a warning'], $assembled['observerWarnings']);
    }

    #[Test]
    public function queuedSingleAndBulkCarryTheStatusUrl(): void
    {
        $assembler = $this->assembler();

        self::assertSame(
            ['message' => 'Organization queued', 'runId' => 'run-3', 'statusUrl' => '/runs/run-3'],
            $assembler->queuedSingle('run-3'),
        );
        self::assertSame(
            ['message' => 'Bulk organization queued', 'runId' => 'run-4', 'statusUrl' => '/runs/run-4', 'objectCount' => 12, 'batchCount' => 3],
            $assembler->queuedBulk('run-4', 12, 3),
        );
    }

    #[Test]
    public function statusUrlDelegatesToTheRunGetRoute(): void
    {
        self::assertSame('/runs/run-5', $this->assembler()->statusUrl('run-5'));
    }
}
