<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\Query\ByteFormat;
use Oronts\AssetPilotBundle\Service\StorageTrendService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:capture-storage-snapshot',
    description: 'Capture a point-in-time unused-storage snapshot for trend reporting',
)]
class CaptureStorageSnapshotCommand extends Command
{
    public function __construct(
        private readonly StorageTrendService $trends,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->trends->capture();

        $io->success(sprintf(
            'Captured snapshot at %s: %d type(s), %d unused asset(s), %s.',
            $result['capturedAt'],
            $result['types'],
            $result['totalCount'],
            ByteFormat::human($result['totalSize']),
        ));

        return Command::SUCCESS;
    }
}
