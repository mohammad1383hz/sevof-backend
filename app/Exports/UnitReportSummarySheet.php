<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// ==========================================
// بذار در: app/Exports/UnitReportSummarySheet.php
// ==========================================
class UnitReportSummarySheet implements FromCollection, WithHeadings, WithTitle, WithStyles
{
    public function __construct(
        private array $unitInfo,
        private int $total,
        private int $approvedCount,
        private int $rejectedCount
    ) {}

    public function title(): string { return 'اطلاعات کلی'; }

    public function headings(): array
    {
        return ['فیلد', 'مقدار'];
    }

    public function collection()
    {
        return collect([
            ['کد واحد',             $this->unitInfo['CodeVahed']],
            ['نام واحد',            $this->unitInfo['VahedName']],
            ['شماره نامه محموله',   $this->unitInfo['Mahmoleh_Letter_No']],
            ['تاریخ نامه',          $this->unitInfo['Mahmoleh_Letter_Date']],
            ['تعداد کل دانشجو',     $this->total],
            ['تعداد تایید شده',     $this->approvedCount],
            ['تعداد رد شده',        $this->rejectedCount],
            ['تعداد در انتظار',     $this->total - $this->approvedCount - $this->rejectedCount],
        ]);
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        $sheet->getStyle('A1:B1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F4E79');
        $sheet->getStyle('A1:B1')->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getStyle('A:B')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}

// ==========================================
// بذار در: app/Exports/UnitReportApprovedSheet.php
// ==========================================
class UnitReportApprovedSheet implements FromCollection, WithHeadings, WithTitle, WithStyles
{
    public function __construct(private $approved) {}

    public function title(): string { return 'تایید شده'; }

    public function headings(): array
    {
        return ['نام', 'نام خانوادگی', 'مقطع تحصیلی', 'شماره دانشجویی', 'وضعیت'];
    }

    public function collection()
    {
        return $this->approved->map(fn($r) => [
            $r->Name,
            $r->Family,
            $r->Maghta_Tahsili,
            $r->StudentNo,
            'تایید شده',
        ]);
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getStyle('A1:E1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1E7E34');
        $sheet->getStyle('A1:E1')->getFont()->getColor()->setRGB('FFFFFF');
        foreach (['A','B','C','D','E'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('A:E')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}

// ==========================================
// بذار در: app/Exports/UnitReportRejectedSheet.php
// ==========================================
class UnitReportRejectedSheet implements FromCollection, WithHeadings, WithTitle, WithStyles
{
    public function __construct(private $rejected) {}

    public function title(): string { return 'رد شده'; }

    public function headings(): array
    {
        return ['نام', 'نام خانوادگی', 'مقطع تحصیلی', 'شماره دانشجویی', 'وضعیت', 'دلیل رد'];
    }

    public function collection()
    {
        return $this->rejected->map(fn($r) => [
            $r->Name,
            $r->Family,
            $r->Maghta_Tahsili,
            $r->StudentNo,
            'رد شده',
            $r->status_resones ?? '-',
        ]);
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('C0392B');
        $sheet->getStyle('A1:F1')->getFont()->getColor()->setRGB('FFFFFF');
        foreach (['A','B','C','D','E','F'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('A:F')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}