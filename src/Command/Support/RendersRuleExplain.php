<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command\Support;

use Oronts\AssetPilotBundle\Model\RuleEvaluation;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders one asset's rule-explain block (per-rule evaluation table + the resolved move decision) shared by the
 * organize and debug-rule commands, so both report the same destination path. An optional rule filter narrows
 * the table. With no evaluations the debugger short-circuits with a note ($announceEmptyEvaluations), while the
 * organize preview still renders the empty table and decision line. The using command must expose
 * `$this->ruleEngine` and `$this->namingStrategy`.
 */
trait RendersRuleExplain
{
    private function renderRuleExplain(SymfonyStyle $io, AbstractObject $object, Asset $asset, ?string $fieldName, ?string $locale, ?string $ruleFilter = null, bool $announceEmptyEvaluations = false): void
    {
        $io->text(sprintf('  Asset #%d: %s', $asset->getId(), $asset->getRealFullPath()));
        $io->newLine();

        $result = $this->ruleEngine->explain($object, $asset, $fieldName, $locale);
        $evaluations = $result['evaluations'];
        $matches = $result['matches'];

        if ($ruleFilter !== null) {
            $evaluations = array_values(array_filter($evaluations, static fn (RuleEvaluation $e): bool => $e->ruleName === $ruleFilter));
        }

        if ($evaluations === [] && $announceEmptyEvaluations) {
            $io->text('  No rules to evaluate.');
            $io->newLine();

            return;
        }

        $rows = [];
        foreach ($evaluations as $eval) {
            $resultLabel = $eval->matched ? '<fg=green>MATCHED</>' : '<fg=yellow>SKIPPED</>';
            $rows[] = [$eval->ruleName, $resultLabel, $eval->describe()];
        }
        $io->table(['Rule', 'Result', 'Detail'], $rows);

        if ($matches !== []) {
            $match = $matches[0];
            $targetFilename = $this->namingStrategy->generateName($match->asset, $match->resolvedPath);
            $fullPath = rtrim($match->resolvedPath, '/') . '/' . $targetFilename;
            $io->text(sprintf('  Decision: Rule "%s" matched -> %s', $match->rule->name, $fullPath));
        } else {
            $io->text('  Decision: No rules matched this asset.');
        }

        $io->newLine();
    }
}
