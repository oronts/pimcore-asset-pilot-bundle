<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\DuplicateDetectionService;
use Oronts\AssetPilotBundle\Service\DuplicateMergeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:merge-duplicates',
    description: 'Consolidate a byte-identical duplicate group onto one canonical asset (preview by default; --apply to merge)',
)]
class MergeDuplicatesCommand extends Command
{
    public function __construct(
        private readonly DuplicateDetectionService $duplicates,
        private readonly DuplicateMergeService $merge,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('checksum', null, InputOption::VALUE_REQUIRED, 'The content hash of the duplicate group to merge (from asset-pilot:find-duplicates)')
            ->addOption('canonical', null, InputOption::VALUE_REQUIRED, 'Asset id to keep as canonical (default: the lowest id in the group)')
            ->addOption('strategy', null, InputOption::VALUE_REQUIRED, 'Disposition strategy for the copies (default: the configured one)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually merge (default: preview only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Merge Duplicates');

        $checksum = (string) ($input->getOption('checksum') ?? '');
        if ($checksum === '') {
            $io->error('--checksum is required.');

            return Command::INVALID;
        }

        $strategy = $input->getOption('strategy');
        if ($strategy !== null && !in_array($strategy, $this->merge->availableStrategies(), true)) {
            $io->error(sprintf('Unknown strategy "%s". Available: %s.', $strategy, implode(', ', $this->merge->availableStrategies())));

            return Command::INVALID;
        }

        $group = $this->duplicates->groupForChecksum($checksum);
        if ($group === null) {
            $io->error('No duplicate group with at least two live assets for that checksum.');

            return Command::FAILURE;
        }

        $canonical = $input->getOption('canonical');
        $canonicalId = $canonical !== null ? (int) $canonical : null;
        $apply = (bool) $input->getOption('apply');

        $outcome = $this->merge->merge($group, $canonicalId, is_string($strategy) ? $strategy : null, !$apply);

        $io->text(sprintf('Canonical asset: %d', $outcome->canonicalId));
        if ($outcome->dispositions !== []) {
            $io->table(
                ['Copy', 'Outcome', 'Reason'],
                array_map(
                    static fn ($disposition): array => [(string) $disposition->copyId, $disposition->outcome->value, $disposition->reason],
                    $outcome->dispositions,
                ),
            );
        }

        if (!$apply) {
            $io->note('Preview only. Re-run with --apply to merge.');

            return Command::SUCCESS;
        }

        $io->success('Merge complete.');

        return Command::SUCCESS;
    }
}
