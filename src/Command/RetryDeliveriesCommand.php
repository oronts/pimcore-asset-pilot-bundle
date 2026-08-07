<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\BoundedIntegerOption;
use Oronts\AssetPilotBundle\Command\Support\ValidatesApplyPlanControl;
use Oronts\AssetPilotBundle\Exception\DeliveryRetryPlanException;
use Oronts\AssetPilotBundle\Model\ActorContext;
use Oronts\AssetPilotBundle\Model\DeadOperationDelivery;
use Oronts\AssetPilotBundle\Service\OperationDeliveryRetryCoordinatorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:retry-deliveries',
    description: 'Preview or requeue exact reviewed dead operation deliveries',
)]
final class RetryDeliveriesCommand extends Command
{
    use ValidatesApplyPlanControl;

    public function __construct(private readonly OperationDeliveryRetryCoordinatorInterface $retries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum dead deliveries to inspect', '100')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Requeue the exact reviewed delivery scope')
            ->addOption('plan-token', null, InputOption::VALUE_REQUIRED, 'Signed token returned by the matching preview');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = BoundedIntegerOption::parse($input->getOption('limit'), 1, 1_000);
        if ($limit === null) {
            $io->error('--limit must be an integer between 1 and 1000.');

            return Command::INVALID;
        }
        $apply = (bool) $input->getOption('apply');
        $token = $input->getOption('plan-token');

        if (!$this->hasValidPlanControl($io, $apply, $token)) {
            return Command::INVALID;
        }

        if (!$apply) {
            $review = $this->retries->preview($limit, ActorContext::system());
            if ($review->deliveries === []) {
                $io->success('No dead operation deliveries require retry.');

                return Command::SUCCESS;
            }

            $this->render($io, $review->deliveries);
            $io->writeln('Plan token: <info>' . $review->planToken . '</info>');
            $io->note('No delivery state was changed.');

            return Command::SUCCESS;
        }

        try {
            $review = $this->retries->apply($limit, ActorContext::system(), $token);
        } catch (DeliveryRetryPlanException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $this->render($io, $review->deliveries);
        $io->success(sprintf('Requeued %d dead operation delivery(ies).', count($review->deliveries)));

        return Command::SUCCESS;
    }

    /** @param list<DeadOperationDelivery> $deliveries */
    private function render(SymfonyStyle $io, array $deliveries): void
    {
        $io->table(
            ['Delivery', 'Operation', 'Observer', 'Outcome', 'Attempts', 'Last error', 'Updated'],
            array_map(static fn (DeadOperationDelivery $delivery): array => [
                $delivery->deliveryId,
                (string) $delivery->operationId,
                $delivery->observerId,
                $delivery->outcome->value,
                (string) $delivery->attempts,
                $delivery->lastError ?? '',
                $delivery->updatedAt,
            ], $deliveries),
        );
    }
}
