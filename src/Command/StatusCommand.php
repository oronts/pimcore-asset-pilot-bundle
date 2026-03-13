<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Oronts\AssetPilotBundle\Engine\RuleEngine;
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
        protected readonly RuleEngine $ruleEngine,
        protected readonly AuditLogger $auditLogger,
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

        $rules = $this->ruleEngine->getRules();
        $stats = $this->auditLogger->getStats();
        $recent = $this->auditLogger->getRecent(10);

        if ($format === 'json') {
            $output->writeln(json_encode([
                'rules' => array_map(fn ($r) => [
                    'name' => $r->name, 'class' => $r->class,
                    'strategy' => $r->strategy->value, 'target_path' => $r->targetPath,
                    'enabled' => $r->enabled,
                ], $rules),
                'stats' => $stats,
                'recent' => $recent,
            ], JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        // Rules table
        $io->section('Configured Rules');
        if (empty($rules)) {
            $io->note('No rules configured.');
        } else {
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

        // Stats
        $io->section('Statistics');
        $io->table(['Metric', 'Count'], [
            ['Completed', $stats['completed'] ?? 0],
            ['Failed', $stats['failed'] ?? 0],
            ['Skipped', $stats['skipped'] ?? 0],
        ]);

        // Recent operations
        $io->section('Recent Operations (last 10)');
        if (empty($recent)) {
            $io->note('No operations recorded yet.');
        } else {
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

        return Command::SUCCESS;
    }
}
