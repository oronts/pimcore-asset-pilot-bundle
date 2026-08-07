<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Enum\OperationStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:status',
    description: 'Show Asset Pilot status: configured rules, recent operations, and statistics',
)]
class StatusCommand extends Command
{
    public function __construct(
        protected readonly RuleEngineInterface $ruleEngine,
        protected readonly AuditQueryInterface $auditLogger,
        protected readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format');
        if (!is_string($format) || !in_array($format, ['table', 'json'], true)) {
            $io->error('--format must be either "table" or "json".');

            return Command::FAILURE;
        }

        try {
            $rules = $this->ruleEngine->getRules();
            $stats = $this->auditLogger->getStats();
            $recent = $this->auditLogger->getRecent(10);
        } catch (\Throwable $error) {
            $this->logger->error('Asset Pilot status could not be loaded.', ['exception' => $error]);
            $io->error('Asset Pilot status could not be loaded.');

            return Command::FAILURE;
        }

        if ($format === 'json') {
            $output->writeln(json_encode($this->jsonStatus($rules, $stats, $recent), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $this->renderRules($io, $rules);
        $this->renderStats($io, $stats);
        $this->renderRecent($io, $recent);

        return Command::SUCCESS;
    }

    private function renderRules(SymfonyStyle $io, array $rules): void
    {
        $io->section('Configured Rules');
        if (empty($rules)) {
            $io->note('No rules configured.');

            return;
        }

        $rows = [];
        foreach ($rules as $rule) {
            $rows[] = [
                $rule->name,
                $rule->class,
                $rule->strategy->value,
                $rule->targetPath,
                $rule->enabled ? 'Yes' : 'No',
                $rule->priority,
            ];
        }
        $io->table(['Name', 'Class', 'Strategy', 'Target Path', 'Enabled', 'Priority'], $rows);
    }

    private function renderStats(SymfonyStyle $io, array $stats): void
    {
        $io->section('Statistics');
        $io->table(['Metric', 'Count'], [
            ['Completed', ($stats[OperationStatus::Completed->value] ?? 0) + ($stats[OperationStatus::CompletedWithObserverError->value] ?? 0)],
            ['Completed with observer warnings', $stats[OperationStatus::CompletedWithObserverError->value] ?? 0],
            ['In progress', $stats[OperationStatus::InProgress->value] ?? 0],
            ['Recovery required', $stats[OperationStatus::RecoveryRequired->value] ?? 0],
            ['Failed', $stats['failed'] ?? 0],
            ['Skipped', $stats['skipped'] ?? 0],
        ]);
    }

    private function renderRecent(SymfonyStyle $io, array $recent): void
    {
        $io->section('Recent Operations (last 10)');
        if (empty($recent)) {
            $io->note('No operations recorded yet.');

            return;
        }

        $rows = [];
        foreach ($recent as $entry) {
            $rows[] = [
                $entry['asset_id'],
                $entry['object_class'],
                $entry['rule_name'],
                $entry['status'],
                $entry['created_at'],
            ];
        }
        $io->table(['Asset', 'Class', 'Rule', 'Status', 'Date'], $rows);
    }

    private function jsonStatus(array $rules, array $stats, array $recent): array
    {
        return [
            'rules' => array_map(static fn ($rule): array => [
                'name' => $rule->name,
                'class' => $rule->class,
                'strategy' => $rule->strategy->value,
                'target_path' => $rule->targetPath,
                'enabled' => $rule->enabled,
            ], $rules),
            'stats' => $stats,
            'recent' => $recent,
        ];
    }
}
