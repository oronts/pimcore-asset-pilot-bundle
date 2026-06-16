<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Audit\AuditLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:audit',
    description: 'View and manage Asset Pilot audit log',
)]
class AuditCommand extends Command
{
    public function __construct(
        protected readonly AuditLogger $auditLogger,
        protected readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Show entries since (e.g., "1 week ago")')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'Filter by DataObject class')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (completed, failed, skipped)')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'Filter by rule name')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum entries to show', '50')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Delete old audit entries based on retention settings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('cleanup')) {
            $days = 90; // Could come from config
            $deleted = $this->auditLogger->cleanup($days);
            $io->success("Cleaned up {$deleted} audit entries older than {$days} days.");
            return Command::SUCCESS;
        }

        $filters = [];
        if ($class = $input->getOption('class')) {
            $filters['object_class'] = $class;
        }
        if ($status = $input->getOption('status')) {
            $filters['status'] = $status;
        }
        if ($rule = $input->getOption('rule')) {
            $filters['rule_name'] = $rule;
        }
        if ($since = $input->getOption('since')) {
            $filters['since'] = (new \DateTimeImmutable($since))->format('Y-m-d H:i:s');
        }

        $entries = $this->auditLogger->getRecent((int) $input->getOption('limit'), $filters);

        if (empty($entries)) {
            $io->note('No audit entries found matching the criteria.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                $entry['id'],
                $entry['asset_id'],
                mb_substr($entry['asset_path_from'], 0, 40),
                mb_substr($entry['asset_path_to'], 0, 40),
                $entry['object_class'],
                $entry['rule_name'],
                $entry['status'],
                $entry['duration_ms'] ? $entry['duration_ms'] . 'ms' : '-',
                $entry['created_at'],
            ];
        }

        $io->table(
            ['ID', 'Asset', 'From', 'To', 'Class', 'Rule', 'Status', 'Duration', 'Date'],
            $rows,
        );

        $io->note(count($entries) . ' entries shown.');

        return Command::SUCCESS;
    }
}
