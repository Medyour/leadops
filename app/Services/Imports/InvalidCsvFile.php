<?php

namespace App\Services\Imports;

use RuntimeException;

final class InvalidCsvFile extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The CSV file is structurally invalid.');
    }
}
