<?php

namespace App\Services\Imports;

final class CsvImportParser
{
    /** @var list<string> */
    public const HEADER = [
        'external_reference',
        'first_name',
        'last_name',
        'company',
        'email',
        'phone',
        'country',
        'service_interest',
        'company_size',
        'message',
        'consent',
        'received_at',
    ];

    /**
     * @return list<array<string, string>>
     *
     * @throws InvalidCsvFile
     */
    public function parse(string $contents): array
    {
        if ($contents === '') {
            throw new InvalidCsvFile(['Le fichier est vide.']);
        }

        if (! mb_check_encoding($contents, 'UTF-8') || $this->containsBinaryControlBytes($contents)) {
            throw new InvalidCsvFile(['Le fichier doit contenir uniquement du texte UTF-8 valide.']);
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if ($contents === '') {
            throw new InvalidCsvFile(['Le fichier est vide.']);
        }

        if (str_contains($contents, "\xEF\xBB\xBF")) {
            throw new InvalidCsvFile(['Le BOM UTF-8 est autorisé uniquement au début du fichier.']);
        }

        $rows = $this->parseRecords($contents);

        if ($rows === []) {
            throw new InvalidCsvFile(['L’en-tête CSV est manquant.']);
        }

        $this->validateHeader($rows[0]);

        $dataRows = array_slice($rows, 1);
        $maximum = (int) config('leadops.csv_import.max_rows');

        if (count($dataRows) > $maximum) {
            throw new InvalidCsvFile(["Le fichier dépasse la limite de {$maximum} lignes de données."]);
        }

        foreach ($dataRows as $index => $row) {
            if (count($row) !== count(self::HEADER)) {
                $line = $index + 2;

                throw new InvalidCsvFile(["La ligne {$line} ne contient pas exactement ".count(self::HEADER).' colonnes.']);
            }
        }

        return array_map(
            static fn (array $row): array => array_combine(self::HEADER, $row),
            $dataRows,
        );
    }

    private function containsBinaryControlBytes(string $contents): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contents) === 1;
    }

    /** @return list<list<string>> */
    private function parseRecords(string $contents): array
    {
        $rows = [];
        $row = [];
        $field = '';
        $state = 'start';
        $length = strlen($contents);

        for ($index = 0; $index < $length; $index++) {
            $character = $contents[$index];

            if ($state === 'quoted') {
                if ($character === '"') {
                    if (($contents[$index + 1] ?? null) === '"') {
                        $field .= '"';
                        $index++;
                    } else {
                        $state = 'after_quote';
                    }
                } else {
                    $field .= $character;
                }

                continue;
            }

            if ($state === 'after_quote') {
                if ($character === ',') {
                    $row[] = $field;
                    $field = '';
                    $state = 'start';

                    continue;
                }

                if ($character === "\r" || $character === "\n") {
                    $this->finishRow($rows, $row, $field);
                    $state = 'start';

                    if ($character === "\r" && ($contents[$index + 1] ?? null) === "\n") {
                        $index++;
                    }

                    continue;
                }

                throw new InvalidCsvFile(['Le fichier contient une valeur citée mal formée.']);
            }

            if ($character === '"') {
                if ($state !== 'start') {
                    throw new InvalidCsvFile(['Le fichier contient un guillemet CSV mal placé.']);
                }

                $state = 'quoted';

                continue;
            }

            if ($character === ',') {
                $row[] = $field;
                $field = '';
                $state = 'start';

                continue;
            }

            if ($character === "\r" || $character === "\n") {
                $this->finishRow($rows, $row, $field);
                $state = 'start';

                if ($character === "\r" && ($contents[$index + 1] ?? null) === "\n") {
                    $index++;
                }

                continue;
            }

            $field .= $character;
            $state = 'unquoted';
        }

        if ($state === 'quoted') {
            throw new InvalidCsvFile(['Le fichier contient une valeur citée non terminée.']);
        }

        if ($state !== 'start' || $row !== [] || $field !== '') {
            $this->finishRow($rows, $row, $field);
        }

        return $rows;
    }

    /** @param list<list<string>> $rows @param list<string> $row */
    private function finishRow(array &$rows, array &$row, string &$field): void
    {
        $row[] = $field;
        $rows[] = $row;
        $row = [];
        $field = '';
    }

    /** @param list<string> $header */
    private function validateHeader(array $header): void
    {
        if ($header === self::HEADER) {
            return;
        }

        $errors = [];
        $counts = array_count_values($header);
        $duplicates = array_values(array_intersect(
            self::HEADER,
            array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)),
        ));
        $missing = array_values(array_diff(self::HEADER, $header));
        $unexpected = array_values(array_diff($header, self::HEADER));

        if ($duplicates !== []) {
            $errors[] = 'En-tête dupliqué : '.implode(', ', $duplicates).'.';
        }

        if ($missing !== []) {
            $errors[] = 'En-tête manquant : '.implode(', ', $missing).'.';
        }

        if ($unexpected !== []) {
            $errors[] = 'Le fichier contient un ou plusieurs en-têtes inattendus.';
        }

        if ($errors === []) {
            $errors[] = 'Les en-têtes CSV ne sont pas dans l’ordre attendu.';
        }

        throw new InvalidCsvFile($errors);
    }
}
