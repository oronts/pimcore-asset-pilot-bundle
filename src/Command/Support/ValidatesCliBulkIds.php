<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Command\Support;

use Oronts\AssetPilotBundle\Support\BulkIds;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console-side counterpart to the REST HandlesBulkIds trait: parse a comma-separated id option
 * through BulkIds::fromCsv and reject empty/garbage or oversized input, so the CLI and the API
 * validate bulk ids identically. Prints the error and returns null; the caller returns
 * Command::INVALID on null.
 */
trait ValidatesCliBulkIds
{
    /**
     * @return list<int>|null null (after printing an error) when the option is empty/all-garbage or
     *                        lists more than BulkIds::MAX ids
     */
    protected function validatedCsvIds(SymfonyStyle $io, ?string $csv, string $label): ?array
    {
        $ids = BulkIds::fromCsv($csv);

        if ($ids === []) {
            $io->error(sprintf('%s must list one or more positive ids.', $label));

            return null;
        }

        if (count($ids) > BulkIds::MAX) {
            $io->error(sprintf('Too many ids for %s: at most %d per run.', $label, BulkIds::MAX));

            return null;
        }

        return $ids;
    }
}
