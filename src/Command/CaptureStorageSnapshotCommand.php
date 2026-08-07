<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\Query\ByteFormat;
use Oronts\AssetPilotBundle\Service\StorageTrendServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:capture-storage-snapshot',
    description: 'Capture a point-in-time unused-storage snapshot for trend reporting',
)]
class CaptureStorageSnapshotCommand extends Command
{
    public function __construct(
        private readonly StorageTrendServiceInterface $trends,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Capture even when a fresh snapshot already exists.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->trends->capture((bool) $input->getOption('force'));
        } catch (\Throwable) {
            $io->error('Storage snapshot capture failed. See server logs.');

            return Command::FAILURE;
        }

        if (!$result['captured']) {
            $io->note((string) $result['reason']);

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Captured snapshot at %s: %d type(s), %d unused asset(s), %s known size, %d unknown size(s).',
            $result['capturedAt'] ?? '-',
            $result['types'],
            $result['totalCount'],
            ByteFormat::human($result['totalSize']),
            $result['unknownSizeCount'],
        ));

        return Command::SUCCESS;
    }
}
