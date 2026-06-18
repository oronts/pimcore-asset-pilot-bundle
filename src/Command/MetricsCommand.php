<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Service\MetricsService;
use Oronts\AssetPilotBundle\Service\PrometheusFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'asset-pilot:metrics',
    description: 'Output operational metrics as Prometheus text exposition (for a textfile collector) or JSON',
)]
class MetricsCommand extends Command
{
    public function __construct(
        private readonly MetricsService $metrics,
        private readonly PrometheusFormatter $formatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: prometheus or json', 'prometheus');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        $metrics = $this->metrics->collect();

        // Raw output (no styling) so it can be piped to a .prom file or a JSON consumer.
        switch ($format) {
            case 'prometheus':
                $output->write($this->formatter->format($metrics));

                return Command::SUCCESS;
            case 'json':
                $output->writeln((string) json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return Command::SUCCESS;
            default:
                $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
                $stderr->writeln('Invalid --format. Use "prometheus" or "json".');

                return Command::INVALID;
        }
    }
}
