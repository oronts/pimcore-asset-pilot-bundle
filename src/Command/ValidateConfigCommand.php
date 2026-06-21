<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Service\ConfigValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:validate-config',
    description: 'Validate all Asset Pilot rule configurations',
)]
class ValidateConfigCommand extends Command
{
    public function __construct(
        private readonly ConfigValidator $configValidator,
        private readonly RuleEngineInterface $ruleEngine,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rules = $this->ruleEngine->getRules();

        if (empty($rules)) {
            $io->warning('No rules configured.');
            return Command::SUCCESS;
        }

        $io->title('Asset Pilot — Config Validation');
        $io->text(sprintf('Validating %d rule(s)...', count($rules)));

        $results = $this->configValidator->validate($rules);

        $rows = [];
        $hasFail = false;

        foreach ($results as $result) {
            $statusIcon = match ($result->status) {
                'pass' => '<fg=green>PASS</>',
                'fail' => '<fg=red>FAIL</>',
                'warning' => '<fg=yellow>WARN</>',
                default => $result->status,
            };

            if ($result->status === 'fail') {
                $hasFail = true;
            }

            $rows[] = [$result->ruleName, $result->check, $statusIcon, $result->message];
        }

        $io->table(['Rule', 'Check', 'Status', 'Detail'], $rows);

        $passCount = count(array_filter($results, static fn ($r) => $r->status === 'pass'));
        $failCount = count(array_filter($results, static fn ($r) => $r->status === 'fail'));
        $warnCount = count(array_filter($results, static fn ($r) => $r->status === 'warning'));

        $io->text(sprintf('%d passed, %d failed, %d warnings', $passCount, $failCount, $warnCount));

        if ($hasFail) {
            $io->error('Configuration has validation errors.');
            return Command::FAILURE;
        }

        $io->success('All configuration checks passed.');

        return Command::SUCCESS;
    }
}
