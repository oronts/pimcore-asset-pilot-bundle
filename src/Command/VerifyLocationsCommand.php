<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\LocationDriftService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:verify-locations',
    description: 'Report assets that are not where the current rules expect them (organization drift)',
)]
class VerifyLocationsCommand extends Command
{
    public function __construct(
        private readonly LocationDriftService $drift,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'DataObject class to scan')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page of objects to scan', '1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Objects per page', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $className = $input->getOption('class');
        if ($className === null || $className === '') {
            $io->error('The --class option is required.');

            return Command::INVALID;
        }

        $result = $this->drift->driftForClass(
            (string) $className,
            max(1, (int) $input->getOption('page')),
            max(1, (int) $input->getOption('limit')),
        );

        $io->title(sprintf('Asset Pilot — Location Drift (%s)', $className));

        if ($result['items'] === []) {
            $io->success(sprintf('No drift across %d scanned object(s).', $result['objectsScanned']));

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn ($item): array => [(string) $item->assetId, $item->ruleName, $item->currentPath, $item->expectedPath],
            $result['items'],
        );
        $io->table(['Asset', 'Rule', 'Current path', 'Expected path'], $rows);
        $io->warning(sprintf('%d drifted asset(s) across %d object(s).', count($result['items']), $result['objectsScanned']));

        return Command::SUCCESS;
    }
}
