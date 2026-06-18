<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\QuarantineService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:quarantine-purge',
    description: 'Hard-delete quarantined assets past the grace period (only those still unused)',
)]
class QuarantinePurgeCommand extends Command
{
    public function __construct(
        private readonly QuarantineService $quarantineService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('grace-days', null, InputOption::VALUE_REQUIRED, 'Override the configured grace period (days)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be purged without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $graceOption = $input->getOption('grace-days');
        if ($graceOption !== null && !ctype_digit((string) $graceOption)) {
            $io->error('--grace-days must be a non-negative integer.');

            return Command::INVALID;
        }
        $graceDays = $graceOption !== null ? (int) $graceOption : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $result = $this->quarantineService->purgeExpired($graceDays, $dryRun);

        $io->title('Asset Pilot — Quarantine Purge' . ($dryRun ? ' (dry run)' : ''));
        $io->definitionList(
            ['purged' => (string) $result['purged']],
            ['skipped' => (string) $result['skipped']],
            ['failed' => (string) $result['failed']],
        );

        if ($result['failed'] > 0) {
            $io->warning(sprintf('%d asset(s) failed to purge; see the log.', $result['failed']));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry run complete.' : 'Purge complete.');

        return Command::SUCCESS;
    }
}
