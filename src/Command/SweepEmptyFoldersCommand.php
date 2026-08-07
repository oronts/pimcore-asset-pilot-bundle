<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use Oronts\AssetPilotBundle\Command\Support\UsesReviewedApplyPlan;
use Oronts\AssetPilotBundle\Exception\StaleApplyPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\ApplyPlan;
use Oronts\AssetPilotBundle\Model\ApplyPlanTarget;
use Oronts\AssetPilotBundle\Service\ApplyPlanServiceInterface;
use Oronts\AssetPilotBundle\Service\EmptyFolderSweepServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:sweep-empty-folders',
    description: 'Find empty asset folders and remove the reviewed result with --apply',
)]
class SweepEmptyFoldersCommand extends Command
{
    use UsesReviewedApplyPlan;

    public function __construct(
        private readonly EmptyFolderSweepServiceInterface $sweep,
        private readonly ApplyPlanServiceInterface $applyPlans,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Restrict the sweep to this folder subtree')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max empty folders to process', '500')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Delete the exact reviewed empty folders (default: preview only)')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed, single-use token returned by the matching preview');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Asset Pilot — Empty Folder Sweep');

        $apply = (bool) $input->getOption('apply');
        $planToken = $input->getOption('plan-token');
        if (!$this->hasValidPlanControl($io, $apply, $planToken)) {
            return Command::INVALID;
        }

        $limit = BoundedIntegerOption::parse($input->getOption('limit'), 1, 1_000);
        if ($limit === null) {
            $io->error('--limit must be an integer between 1 and 1000.');

            return Command::INVALID;
        }
        $rootOption = $input->getOption('folder');
        $root = $rootOption === null ? null : (string) $rootOption;
        $found = $this->sweep->findEmpty($root, 1, $limit);

        if ($found['items'] === []) {
            if ($apply) {
                $io->error('The reviewed empty-folder selection is no longer current. Preview again.');

                return Command::INVALID;
            }
            $io->success('No empty folders found.');

            return Command::SUCCESS;
        }

        $io->table(['Folder', 'Path'], array_map(static fn (array $item): array => [(string) $item['id'], $item['path']], $found['items']));

        $folderIds = array_map(static fn (array $item): int => $item['id'], $found['items']);
        sort($folderIds, SORT_NUMERIC);
        $servicePlan = $this->sweep->createDeletePlan($folderIds);
        $plan = $this->plan($servicePlan, $root, $limit, $folderIds);

        if (!$apply) {
            $this->sweep->previewDelete($folderIds);
            $this->renderPlanToken($io, $this->applyPlans->issue($plan));
            $io->note(sprintf('%d empty folder(s) are included in the reviewed selection.', count($found['items'])));

            return Command::SUCCESS;
        }

        if (!$this->claimPlan($io, (string) $planToken, $plan)) {
            return Command::INVALID;
        }
        try {
            $result = $this->sweep->deleteEmpty($folderIds, $plan->fingerprintMap());
        } catch (StaleApplyPlanException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $io->definitionList(
            ['deleted' => (string) $result['deleted']],
            ['skipped' => (string) $result['skipped']],
            ['failed' => (string) $result['failed']],
        );

        if ($result['failed'] > 0) {
            $io->warning(sprintf('%d folder(s) could not be deleted; see the log.', $result['failed']));

            return Command::FAILURE;
        }

        $io->success(sprintf('Swept %d empty folder(s).', $result['deleted']));

        return Command::SUCCESS;
    }

    /** @param list<int> $folderIds */
    private function plan(ApplyPlan $servicePlan, ?string $root, int $limit, array $folderIds): ApplyPlan
    {
        $targets = $servicePlan->targets;
        usort($targets, static fn (ApplyPlanTarget $left, ApplyPlanTarget $right): int => strcmp($left->id, $right->id));

        return new ApplyPlan(
            'empty-folder-delete-cli',
            ActorContext::system(),
            ['folderIds' => $folderIds, 'selector' => ['folder' => $root, 'limit' => $limit]],
            $servicePlan->config,
            $targets,
        );
    }

}
