<?php

namespace App\Modules\Finance\Exports;

use Maatwebsite\Excel\Concerns\FromArray;

class FinancialStatementExport implements FromArray
{
    public function __construct(private readonly array $data) {}

    public function array(): array
    {
        return [['Financial statement data'], [json_encode($this->data, JSON_PRETTY_PRINT)]];
    }
}
