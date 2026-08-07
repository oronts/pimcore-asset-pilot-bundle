<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command\Support;

use Oronts\AssetPilotBundle\Enum\ReviewedSelectionError;
use Oronts\AssetPilotBundle\Exception\ReviewedSelectionException;
use Oronts\AssetPilotBundle\Model\MoveOperation;
use Oronts\AssetPilotBundle\Model\ReviewedSelectionResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ReviewedSelectionConsolePresenter
{
    /** @param array<string, int|string> $summary */
    public function render(SymfonyStyle $io, ReviewedSelectionResult $result, array $summary): void
    {
        $rows = [];
        foreach ($summary as $label => $value) {
            $rows[] = [$label => (string) $value];
        }
        array_push(
            $rows,
            ['organized' => (string) $result->organized],
            ['dispatched' => (string) $result->dispatched],
            ['skipped' => (string) $result->skipped],
            ['failed' => (string) $result->failed],
        );
        if ($result->planToken !== null) {
            $rows[] = ['plan token' => $result->planToken];
        }
        if ($result->runId !== null) {
            $rows[] = ['run id' => $result->runId];
        }
        if ($result->runStatus !== null) {
            $rows[] = ['run status' => $result->runStatus->value];
        }
        $io->definitionList(...$rows);

        if ($result->operations !== []) {
            $io->table(
                ['Object', 'Asset', 'Source', 'Target', 'Rule', 'Status'],
                array_map(static fn (MoveOperation $operation): array => [
                    $operation->objectId,
                    $operation->assetId,
                    $operation->sourcePath,
                    $operation->targetPath,
                    $operation->ruleName,
                    $operation->status->value,
                ], $result->operations),
            );
        }
    }

    public function renderError(SymfonyStyle $io, ReviewedSelectionException $error): int
    {
        $io->error($error->getMessage());
        if ($error->runId !== null) {
            $io->definitionList(['run id' => $error->runId]);
        }

        return in_array($error->error, [
            ReviewedSelectionError::SelectionTooLarge,
            ReviewedSelectionError::MissingPlanToken,
            ReviewedSelectionError::MalformedPlanToken,
        ], true) ? Command::INVALID : Command::FAILURE;
    }
}
