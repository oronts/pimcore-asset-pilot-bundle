<?php

declare(strict_types=1);

namespace Oronts\AssetPilotBundle\Controller\Api\Support;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream tabular data to the client as a CSV download. Shared by every list controller so exports
 * behave identically: cells are formula-injection-neutralised and the output buffer is flushed every
 * 1000 rows, so an arbitrarily large export stays memory-flat. Pass a generator for `$rows` to page a
 * data source lazily.
 */
trait StreamsCsv
{
    /**
     * @param list<string>                $header
     * @param iterable<array<int, mixed>> $rows
     */
    protected function streamCsv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($header, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $header);

            $written = 0;
            foreach ($rows as $row) {
                fputcsv($handle, array_map($this->sanitizeCsvCell(...), $row));
                if ((++$written % 1000) === 0) {
                    flush();
                }
            }

            fclose($handle);
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    /**
     * A leading formula marker, including one hidden behind whitespace, can be executed by spreadsheet software. A leading control byte is also neutralised.
     */
    protected function sanitizeCsvCell(mixed $value): string
    {
        $value = (string) $value;

        if ($value !== '' && preg_match('/^(?:[\x00-\x1F]|[ \t\r\n\f\v]*[=+@-])/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }
}
