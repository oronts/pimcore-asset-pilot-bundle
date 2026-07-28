<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\RulePortabilityInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'asset-pilot:rules-export',
    description: 'Export the configured rule set as a portable JSON or YAML artifact',
)]
class RulesExportCommand extends Command
{
    public function __construct(
        private readonly RulePortabilityInterface $portability,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: json or yaml', 'json')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['json', 'yaml'], true)) {
            $io->error(sprintf('Unsupported format "%s". Use json or yaml.', $format));

            return Command::INVALID;
        }

        $artifact = $this->portability->export();

        if ($format === 'yaml') {
            $serialized = Yaml::dump($artifact, 6, 2);
        } else {
            $json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                $io->error('Failed to encode the rule set as JSON.');

                return Command::FAILURE;
            }
            $serialized = $json . "\n";
        }

        $file = $input->getOption('output');
        if ($file !== null) {
            if (file_put_contents((string) $file, $serialized) === false) {
                $io->error(sprintf('Could not write to "%s".', $file));

                return Command::FAILURE;
            }
            $io->success(sprintf('Exported %d rule(s) to %s', count($artifact['rules']), $file));

            return Command::SUCCESS;
        }

        $output->write($serialized);

        return Command::SUCCESS;
    }
}
