<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Audit\AuditQueryInterface;
use Oronts\AssetPilotBundle\Audit\AuditRetentionInterface;
use Oronts\AssetPilotBundle\Service\Query\UtcSinceCutoff;
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
        private readonly AuditQueryInterface $auditQuery,
        private readonly AuditRetentionInterface $auditRetention,
        protected readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Show entries since (e.g., "1 week ago")')
            ->addOption('asset-id', null, InputOption::VALUE_REQUIRED, 'Filter to a single asset id')
            ->addOption('object-id', null, InputOption::VALUE_REQUIRED, 'Filter to a single object id')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'Filter by DataObject class')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (pending, in_progress, recovery_required, completed, completed_with_observer_error, failed, skipped)')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'Filter by rule name')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum entries to show', '50')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Delete old audit entries based on retention settings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('cleanup')) {
            return $this->cleanup($io);
        }

        $filters = $this->filters($input, $io);
        if ($filters === false) {
            return Command::INVALID;
        }

        $entries = $this->auditQuery->getRecent((int) $input->getOption('limit'), $filters);
        if (empty($entries)) {
            $io->note('No audit entries found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->renderEntries($io, $entries);

        return Command::SUCCESS;
    }

    private function cleanup(SymfonyStyle $io): int
    {
        $days = $this->auditRetention->getRetentionDays();
        $deleted = $this->auditRetention->cleanup($days);
        $io->success("Cleaned up {$deleted} audit entries older than {$days} days.");

        return Command::SUCCESS;
    }

    /** @return array<string, int|string>|false */
    private function filters(InputInterface $input, SymfonyStyle $io): array|false
    {
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

        foreach (['asset-id' => 'asset_id', 'object-id' => 'object_id'] as $option => $filterKey) {
            $raw = $input->getOption($option);
            if ($raw === null) {
                continue;
            }
            if (!ctype_digit((string) $raw) || (int) $raw <= 0) {
                $io->error(sprintf('--%s must be a positive integer.', $option));

                return false;
            }
            $filters[$filterKey] = (int) $raw;
        }

        $since = $input->getOption('since');
        if (!$since) {
            return $filters;
        }

        try {
            $filters['since'] = UtcSinceCutoff::parse((string) $since);
        } catch (\Exception) {
            $io->error('--since must be a valid date or relative date expression.');

            return false;
        }

        return $filters;
    }

    /** @param list<array<string, mixed>> $entries */
    private function renderEntries(SymfonyStyle $io, array $entries): void
    {
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
    }

}
