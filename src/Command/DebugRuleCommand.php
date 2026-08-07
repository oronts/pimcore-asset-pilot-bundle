<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command;

use Oronts\AssetPilotBundle\Command\Support\RendersRuleExplain;
use Oronts\AssetPilotBundle\Engine\RuleEngineInterface;
use Oronts\AssetPilotBundle\Naming\NamingStrategyInterface;
use Oronts\AssetPilotBundle\Service\AssetFieldExtractorInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'asset-pilot:debug-rule',
    description: 'Debug rule evaluation for a specific data object',
)]
class DebugRuleCommand extends Command
{
    use RendersRuleExplain;

    public function __construct(
        private readonly RuleEngineInterface $ruleEngine,
        private readonly AssetFieldExtractorInterface $fieldExtractor,
        private readonly NamingStrategyInterface $namingStrategy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('object-id', 'o', InputOption::VALUE_REQUIRED, 'DataObject ID to debug')
            ->addOption('asset-id', 'a', InputOption::VALUE_OPTIONAL, 'Specific asset ID to debug')
            ->addOption('rule', 'r', InputOption::VALUE_OPTIONAL, 'Filter output to a single rule name')
            ->addOption('field', 'f', InputOption::VALUE_OPTIONAL, 'Filter to a specific field name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $objectId = $input->getOption('object-id');
        if ($objectId === null) {
            $io->error('--object-id is required.');
            return Command::FAILURE;
        }

        $object = AbstractObject::getById((int) $objectId);
        if ($object === null) {
            $io->error("Object #{$objectId} not found.");
            return Command::FAILURE;
        }

        $ruleFilter = $input->getOption('rule');
        $fieldFilter = $input->getOption('field');
        $assetIdFilter = $input->getOption('asset-id');

        $className = $object instanceof Concrete ? $object->getClassName() : 'Folder';
        $io->title(sprintf('Asset Pilot — Rule Debugger for %s #%d', $className, $object->getId()));

        if ($assetIdFilter !== null) {
            $asset = Asset::getById((int) $assetIdFilter);
            if ($asset === null) {
                $io->error("Asset #{$assetIdFilter} not found.");
                return Command::FAILURE;
            }

            $this->debugAsset($io, $object, $asset, null, null, $ruleFilter);

            return Command::SUCCESS;
        }

        $fieldInfos = $this->fieldExtractor->extract($object);

        if (empty($fieldInfos)) {
            $io->warning('No asset fields found on this object.');
            return Command::SUCCESS;
        }

        foreach ($fieldInfos as $fieldInfo) {
            if ($fieldFilter !== null && $fieldInfo->fieldName !== $fieldFilter) {
                continue;
            }

            $localeLabel = $fieldInfo->locale ?? 'none';
            $io->section(sprintf('Field: %s (locale: %s)', $fieldInfo->fieldName, $localeLabel));

            foreach ($fieldInfo->assets as $asset) {
                $this->debugAsset($io, $object, $asset, $fieldInfo->fieldName, $fieldInfo->locale, $ruleFilter);
            }
        }

        return Command::SUCCESS;
    }

    private function debugAsset(
        SymfonyStyle $io,
        AbstractObject $object,
        Asset $asset,
        ?string $fieldName,
        ?string $locale,
        ?string $ruleFilter,
    ): void {
        $this->renderRuleExplain($io, $object, $asset, $fieldName, $locale, $ruleFilter, announceEmptyEvaluations: true);
    }
}
