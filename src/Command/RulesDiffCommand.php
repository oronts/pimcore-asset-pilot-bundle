<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Model\RuleSetDiff;
use Oronts\AssetPilotBundle\Service\ConfigValidator;
use Oronts\AssetPilotBundle\Service\RulePortability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'asset-pilot:rules-diff',
    description: 'Diff an exported rule-set artifact against the current rules (read-only, writes nothing)',
)]
class RulesDiffCommand extends Command
{
    public function __construct(
        private readonly RulePortability $portability,
        private readonly ConfigValidator $configValidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to a JSON or YAML rule-set artifact')
            ->addOption('fail-on-diff', null, InputOption::VALUE_NONE, 'Exit non-zero if the artifact differs from the current rules (CI drift gate)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');

        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('File not found or unreadable: %s', $file));

            return Command::INVALID;
        }

        $artifact = $this->parse($file, (string) file_get_contents($file));
        if ($artifact === null) {
            $io->error('Could not parse the artifact (expected a JSON or YAML object).');

            return Command::INVALID;
        }

        $io->title('Asset Pilot — Rule Set Diff');

        if (!$this->validateImported($io, $artifact)) {
            return Command::FAILURE;
        }

        $diff = $this->portability->diff($artifact);

        if (!$diff->hasChanges()) {
            $io->success('No differences. The artifact matches the current rules.');

            return Command::SUCCESS;
        }

        $this->renderDiff($io, $diff);

        return $input->getOption('fail-on-diff') ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array{rules?: array<string, mixed>}|null
     */
    protected function parse(string $file, string $contents): ?array
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        try {
            $data = in_array($extension, ['yaml', 'yml'], true)
                ? Yaml::parse($contents)
                : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param array{rules?: array<string, mixed>} $artifact
     */
    protected function validateImported(SymfonyStyle $io, array $artifact): bool
    {
        $rules = [];
        foreach ($artifact['rules'] ?? [] as $name => $config) {
            if (!is_array($config) || !isset($config['class'], $config['target_path'])) {
                $io->error(sprintf('Rule "%s" is missing required keys (class, target_path).', $name));

                return false;
            }
            try {
                $rules[] = Rule::fromConfig((string) $name, $config);
            } catch (\Throwable $e) {
                $io->error(sprintf('Rule "%s" is invalid: %s', $name, $e->getMessage()));

                return false;
            }
        }

        $failures = array_filter(
            $this->configValidator->validate($rules),
            static fn ($result): bool => $result->status === 'fail',
        );

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $io->writeln(sprintf('  <fg=red>FAIL</> %s: %s — %s', $failure->ruleName, $failure->check, $failure->message));
            }
            $io->error('The imported artifact has invalid rules; resolve them before comparing.');

            return false;
        }

        return true;
    }

    protected function renderDiff(SymfonyStyle $io, RuleSetDiff $diff): void
    {
        $rows = [];
        foreach (array_keys($diff->added) as $name) {
            $rows[] = ['<fg=green>added</>', $name];
        }
        foreach (array_keys($diff->removed) as $name) {
            $rows[] = ['<fg=red>removed</>', $name];
        }
        foreach (array_keys($diff->changed) as $name) {
            $rows[] = ['<fg=yellow>changed</>', $name];
        }
        foreach ($diff->unchanged as $name) {
            $rows[] = ['unchanged', $name];
        }

        $io->table(['Change', 'Rule'], $rows);
        $io->text(sprintf(
            '%d added, %d removed, %d changed, %d unchanged',
            count($diff->added),
            count($diff->removed),
            count($diff->changed),
            count($diff->unchanged),
        ));
    }
}
