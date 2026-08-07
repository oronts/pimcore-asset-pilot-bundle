<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Enum\CsvDistributionOutcome;
use Oronts\AssetPilotBundle\Model\CsvDistributionResult;
use Oronts\AssetPilotBundle\Service\CsvDistributionServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:distribute-from-csv',
    description: 'Distribute existing assets into target folders from a CSV mapping (preview by default; --apply to move)',
)]
class DistributeFromCsvCommand extends Command
{
    public function __construct(
        private readonly CsvDistributionServiceInterface $distribution,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV mapping file')
            ->addOption('asset-column', null, InputOption::VALUE_REQUIRED, 'Header name of the column holding an asset id or path', 'asset')
            ->addOption('target-column', null, InputOption::VALUE_REQUIRED, 'Header name of the column holding the target folder id or path', 'target')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Move the assets; without this option the command only previews');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $assetColumn = (string) $input->getOption('asset-column');
        $targetColumn = (string) $input->getOption('target-column');
        $apply = (bool) $input->getOption('apply');

        try {
            $report = $this->distribution->distribute($file, $assetColumn, $targetColumn, dryRun: !$apply);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Asset Pilot — CSV Distribution' . ($apply ? '' : ' (preview)'));

        if ($report->total() === 0) {
            $io->warning('The CSV contained no data rows.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Row', 'Asset', 'Target', 'Outcome', 'From', 'To', 'Note'],
            array_map(static fn (CsvDistributionResult $result): array => [
                (string) $result->rowNumber,
                $result->assetReference,
                $result->targetReference,
                $result->outcome->value,
                (string) $result->fromPath,
                (string) $result->toPath,
                $result->message,
            ], $report->results),
        );

        $summary = sprintf(
            '%d moved, %d planned, %d skipped, %d unresolved of %d rows',
            $report->countOf(CsvDistributionOutcome::Moved),
            $report->countOf(CsvDistributionOutcome::Planned),
            $report->countOf(CsvDistributionOutcome::Skipped),
            $report->problemCount(),
            $report->total(),
        );

        if ($report->problemCount() > 0) {
            $io->warning($summary . '. Fix the unresolved rows and re-run.');

            return Command::FAILURE;
        }

        if ($apply) {
            $io->success($summary . '.');
        } else {
            $io->success($summary . '. Re-run with --apply to move the planned assets.');
        }

        return Command::SUCCESS;
    }
}
