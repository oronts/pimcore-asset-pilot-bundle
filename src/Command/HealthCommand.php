<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Enum\HealthStatus;
use Oronts\AssetPilotBundle\Health\HealthChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:health',
    description: 'Run Asset Pilot health checks (audit table, rule config, shared cache, async transport)',
)]
class HealthCommand extends Command
{
    public function __construct(
        private readonly HealthChecker $healthChecker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Health');

        $results = $this->healthChecker->run();

        $rows = [];
        foreach ($results as $result) {
            $rows[] = [$result->name, $this->icon($result->status), $result->message];
        }
        $io->table(['Check', 'Status', 'Detail'], $rows);

        $overall = $this->healthChecker->overall($results);

        if ($overall === HealthStatus::Critical) {
            $io->error('Health: CRITICAL');

            return Command::FAILURE;
        }
        if ($overall === HealthStatus::Warning) {
            $io->warning('Health: WARNING');

            return Command::SUCCESS;
        }

        $io->success('Health: OK');

        return Command::SUCCESS;
    }

    private function icon(HealthStatus $status): string
    {
        return match ($status) {
            HealthStatus::Ok => '<fg=green>OK</>',
            HealthStatus::Warning => '<fg=yellow>WARN</>',
            HealthStatus::Critical => '<fg=red>CRITICAL</>',
        };
    }
}
