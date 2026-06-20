<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Enum\HealOutcome;
use Oronts\AssetPilotBundle\Service\AssetIntegrityService;
use Oronts\AssetPilotBundle\Service\VersionRollbackHealer;
use Oronts\AssetPilotBundle\Support\BulkIds;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:heal-assets',
    description: 'Roll broken assets back to their last renderable version (destructive; --dry-run to preview, --undo to reverse)',
)]
class HealAssetsCommand extends Command
{
    public function __construct(
        private readonly AssetIntegrityService $integrity,
        private readonly VersionRollbackHealer $healer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('by-ids', null, InputOption::VALUE_REQUIRED, 'Heal (or undo) only these specific asset ids (comma-separated)')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Restrict the broken-asset scan to this folder')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to an asset type')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to a file extension')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max assets to scan for breakage', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview the rollback without writing')
            ->addOption('undo', null, InputOption::VALUE_NONE, 'Reverse the most recent heal of each --by-ids asset (requires --by-ids)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawIds = $input->getOption('by-ids');
        $byIds = $this->parseIds($rawIds);
        if ($rawIds !== null && $byIds === []) {
            $io->error('--by-ids must list one or more positive asset ids.');

            return Command::INVALID;
        }

        if ($input->getOption('undo')) {
            return $this->undo($io, $byIds);
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $io->title($dryRun ? 'Asset Pilot — Heal Assets (dry run)' : 'Asset Pilot — Heal Assets');

        // Resolve the candidate broken assets: an explicit id set, or a bounded scan.
        if ($byIds !== null) {
            $scan = $this->integrity->checkAssets($byIds);
        } else {
            $filters = array_filter([
                'folder' => $input->getOption('folder'),
                'type' => $input->getOption('type'),
                'extension' => $input->getOption('extension'),
            ], static fn ($value): bool => $value !== null);
            $scan = $this->integrity->findBroken($filters, 1, max(1, (int) $input->getOption('limit')));
        }

        if ($scan['broken'] === 0) {
            $io->success(sprintf('No broken assets among %d checked.', $scan['scanned']));

            return Command::SUCCESS;
        }

        $tally = array_fill_keys(array_map(static fn (HealOutcome $o): string => $o->value, HealOutcome::cases()), 0);
        $rows = [];
        foreach ($scan['items'] as $item) {
            $result = $this->healer->healById((int) $item['id'], $dryRun);
            ++$tally[$result->outcome->value];
            $rows[] = [(string) $item['id'], $item['path'], $result->outcome->value, $result->toVersion !== null ? (string) $result->toVersion : '-'];
        }

        $io->table(['Asset', 'Path', 'Outcome', 'To version'], $rows);
        $io->definitionList(...array_map(static fn (string $k, int $v): array => [$k => (string) $v], array_keys($tally), array_values($tally)));

        if ($tally[HealOutcome::Unrecoverable->value] > 0) {
            $io->warning(sprintf('%d asset(s) could not be healed (no renderable version); see the integrity log.', $tally[HealOutcome::Unrecoverable->value]));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry run complete.' : 'Heal complete.');

        return Command::SUCCESS;
    }

    private function undo(SymfonyStyle $io, ?array $byIds): int
    {
        if ($byIds === null) {
            $io->error('--undo requires --by-ids to name the assets to reverse.');

            return Command::INVALID;
        }

        $io->title('Asset Pilot — Undo Heal');
        $undone = 0;
        foreach ($byIds as $id) {
            if ($this->healer->undo($id)) {
                ++$undone;
            }
        }

        $io->success(sprintf('Reversed %d of %d heal(s).', $undone, count($byIds)));

        return Command::SUCCESS;
    }

    /**
     * @return list<int>|null null when the option is absent; INVALID handling is left to the caller
     */
    private function parseIds(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        return BulkIds::fromCsv((string) $raw);
    }
}
