<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Model\ReplayResult;
use Oronts\AssetPilotBundle\Service\FailureReplayService;
use Oronts\AssetPilotBundle\Support\BulkIds;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:replay-failures',
    description: 'Re-run the objects whose organization failed, from the audit log',
)]
class ReplayFailuresCommand extends Command
{
    public function __construct(
        private readonly FailureReplayService $replay,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('object-id', null, InputOption::VALUE_REQUIRED, 'Replay only these specific object ids (comma-separated), instead of every failed object')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only failures at or after this date (e.g. "-7 days")')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'Only failures from this rule')
            ->addOption('class', null, InputOption::VALUE_REQUIRED, 'Only failures for this object class')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Queue re-organization via Messenger instead of running inline')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max distinct failed objects to replay', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Replay Failures');

        $async = (bool) $input->getOption('async');

        $objectIds = $input->getOption('object-id');
        if ($objectIds !== null) {
            $ids = BulkIds::fromCsv((string) $objectIds);
            if ($ids === []) {
                $io->error('--object-id must list one or more positive object ids.');

                return Command::INVALID;
            }

            return $this->report($io, $this->replay->replayObjects($ids, $async), $async);
        }

        $since = $input->getOption('since');
        if ($since !== null) {
            $timestamp = strtotime((string) $since);
            if ($timestamp === false) {
                $io->error(sprintf('Could not parse --since value "%s".', $since));

                return Command::INVALID;
            }
            $since = date('Y-m-d H:i:s', $timestamp);
        }

        $filters = array_filter([
            'since' => $since,
            'rule_name' => $input->getOption('rule'),
            'object_class' => $input->getOption('class'),
        ], static fn ($value): bool => $value !== null);

        $limit = max(1, (int) $input->getOption('limit'));

        return $this->report($io, $this->replay->replay($filters, $async, $limit), $async);
    }

    private function report(SymfonyStyle $io, ReplayResult $result, bool $async): int
    {
        $io->definitionList(
            ['candidates' => (string) $result->candidates],
            ['organized' => (string) $result->organized],
            ['dispatched' => (string) $result->dispatched],
            ['skipped' => (string) $result->skipped],
            ['failed' => (string) $result->failed],
        );

        if ($result->failed > 0) {
            $io->warning(sprintf('%d object(s) failed to re-organize; see the log.', $result->failed));

            return Command::FAILURE;
        }

        $io->success($async ? 'Replay queued.' : 'Replay complete.');

        return Command::SUCCESS;
    }
}
