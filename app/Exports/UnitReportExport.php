<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * فایل رو بذار در: app/Exports/UnitReportExport.php
 * نصب: composer require maatwebsite/excel
 */
class UnitReportExport implements WithMultipleSheets
{
    public function __construct(
        private array $unitInfo,
        private $approved,
        private $rejected,
        private int $total
    ) {}

    public function sheets(): array
    {
        return [
            'اطلاعات کلی'  => new UnitReportSummarySheet($this->unitInfo, $this->total, $this->approved->count(), $this->rejected->count()),
            'تایید شده'    => new UnitReportApprovedSheet($this->approved),
            'رد شده'       => new UnitReportRejectedSheet($this->rejected),
        ];
    }
}