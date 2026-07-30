<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\LocationDriftServiceInterface;
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
        private readonly LocationDriftServiceInterface $drift,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('object-id', null, InputOption::VALUE_REQUIRED, 'Check drift for a single object id instead of scanning a class')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'DataObject class to scan')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page of objects to scan', '1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Objects per page', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $objectId = $input->getOption('object-id');
        $className = $input->getOption('class');

        if ($objectId !== null && $className !== null && $className !== '') {
            $io->error('Provide either --object-id or --class, not both.');

            return Command::INVALID;
        }

        if ($objectId !== null) {
            if (!ctype_digit((string) $objectId) || (int) $objectId <= 0) {
                $io->error('--object-id must be a positive integer.');

                return Command::INVALID;
            }
            $result = $this->drift->driftForObjectId((int) $objectId);
            if ($result === null) {
                $io->error(sprintf('Object %d was not found.', (int) $objectId));

                return Command::INVALID;
            }
            $io->title(sprintf('Asset Pilot — Location Drift (object %d)', (int) $objectId));
        } elseif ($className !== null && $className !== '') {
            $result = $this->drift->driftForClass(
                (string) $className,
                max(1, (int) $input->getOption('page')),
                max(1, (int) $input->getOption('limit')),
            );
            $io->title(sprintf('Asset Pilot — Location Drift (%s)', $className));
        } else {
            $io->error('Provide either --object-id or --class.');

            return Command::INVALID;
        }

        if ($result['truncated'] ?? false) {
            $io->warning(sprintf('The authorized scan stopped at the candidate budget; results are limited to the %d object(s) scanned.', $result['objectsScanned']));
        }

        if ($result['items'] === []) {
            $io->success(sprintf('No drift across %d scanned object(s).', $result['objectsScanned']));

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn ($item): array => [(string) $item->assetId, $item->ruleName, $item->currentPath, $item->expectedPath, $item->eligibility->value, $item->reason ?? ''],
            $result['items'],
        );
        $io->table(['Asset', 'Rule', 'Current path', 'Expected path', 'Eligibility', 'Reason'], $rows);
        $io->warning(sprintf('%d drifted asset(s) across %d object(s).', count($result['items']), $result['objectsScanned']));

        return Command::SUCCESS;
    }
}
