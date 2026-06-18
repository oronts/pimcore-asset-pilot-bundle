<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\RuleOverlapAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:rule-overlap',
    description: 'Report rules that compete for the same assets (overlap and shadow analysis)',
)]
class RuleOverlapCommand extends Command
{
    public function __construct(
        private readonly RuleOverlapAnalyzer $analyzer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Rule Overlap');

        $overlaps = $this->analyzer->analyze();
        if ($overlaps === []) {
            $io->success('No overlapping rules detected.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($overlaps as $overlap) {
            $resolution = $overlap->samePriority
                ? '<fg=yellow>ambiguous (same priority)</>'
                : sprintf('%s wins', $overlap->higherPriority);
            $rows[] = [
                $overlap->ruleA,
                $overlap->ruleB,
                $overlap->class,
                implode(', ', $overlap->sharedFields),
                $resolution,
            ];
        }
        $io->table(['Rule A', 'Rule B', 'Class', 'Shared fields', 'Resolution'], $rows);

        $io->note(sprintf(
            '%d overlapping pair(s). Overlap can be intentional (e.g. a wildcard fallback); same-priority pairs are ambiguous and should be re-prioritized.',
            count($overlaps),
        ));

        return Command::SUCCESS;
    }
}
