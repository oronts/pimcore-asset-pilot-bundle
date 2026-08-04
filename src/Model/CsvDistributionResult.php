<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Model;

use Oronts\AssetPilotBundle\Enum\CsvDistributionOutcome;

/** The immutable outcome of one CSV distribution row. */
readonly class CsvDistributionResult
{
    public function __construct(
        public int $rowNumber,
        public string $assetReference,
        public string $targetReference,
        public CsvDistributionOutcome $outcome,
        public ?int $assetId = null,
        public ?string $fromPath = null,
        public ?string $toPath = null,
        public string $message = '',
    ) {}
}
