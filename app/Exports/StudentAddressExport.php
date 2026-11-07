<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StudentAddressExport implements FromArray, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    protected array $students;

    public function __construct(array $students)
    {
        $this->students = $students;
    }

    public function array(): array
    {
        return $this->students;
    }

    public function headings(): array
    {
        return [
            'شماره دانشجویی',
            'کد ملی',
            'نام',
            'نام خانوادگی',
            'کد پستی اولیه',
            'آدرس اولیه',
            'موبایل',
            'تاریخ تولد',
            'وضعیت API',
            'کد پستی دقیق',
            'خیابان',
            'شهر',
            'استان',
            'نام ساختمان',
            'توضیحات',
            'طبقه',
            'پلاک',
            'کد محله',
            'نوع محله',
            'طبقه جانبی',
            'خیابان 2',
            'محله فرعی',
            'بخش',
            'روستا',
            'منطقه',
            'آدرس کامل API',
            'ورودی',
            'شناسه ساختمان',
            'نام پلاک',
            'پیام خطا',
        ];
    }

    public function map($student): array
    {
        return [
            $student['student_number'] ?? '',
            $student['national_id'] ?? '',
            $student['first_name'] ?? '',
            $student['last_name'] ?? '',
            $student['postal_code'] ?? '',
            $student['address'] ?? '',
            $student['mobile'] ?? '',
            $student['birthdate'] ?? '',
            $student['api_status'] ?? '',
            $student['api_postal_code'] ?? '',
            $student['api_street'] ?? '',
            $student['api_locality_name'] ?? '',
            $student['api_province'] ?? '',
            $student['api_building_name'] ?? '',
            $student['api_description'] ?? '',
            $student['api_floor'] ?? '',
            $student['api_house_number'] ?? '',
            $student['api_locality_code'] ?? '',
            $student['api_locality_type'] ?? '',
            $student['api_side_floor'] ?? '',
            $student['api_street2'] ?? '',
            $student['api_sub_locality'] ?? '',
            $student['api_town_ship'] ?? '',
            $student['api_village'] ?? '',
            $student['api_zone'] ?? '',
            $student['api_full_address'] ?? '',
            $student['api_entrance'] ?? '',
            $student['api_building_id'] ?? '',
            $student['api_plate_name'] ?? '',
            $student['api_message'] ?? $student['api_error'] ?? '',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2EFDA']
                ]
            ],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15,  // شماره دانشجویی
            'B' => 15,  // کد ملی
            'C' => 15,  // نام
            'D' => 15,  // نام خانوادگی
            'E' => 15,  // کد پستی اولیه
            'F' => 40,  // آدرس اولیه
            'G' => 15,  // موبایل
            'H' => 15,  // تاریخ تولد
            'I' => 15,  // وضعیت API
            'J' => 15,  // کد پستی دقیق
            'K' => 30,  // خیابان
            'L' => 15,  // شهر
            'M' => 15,  // استان
            'N' => 20,  // نام ساختمان
            'O' => 30,  // توضیحات
            'P' => 10,  // طبقه
            'Q' => 10,  // پلاک
            'R' => 15,  // کد محله
            'S' => 15,  // نوع محله
            'T' => 10,  // طبقه جانبی
            'U' => 30,  // خیابان 2
            'V' => 20,  // محله فرعی
            'W' => 15,  // بخش
            'X' => 15,  // روستا
            'Y' => 15,  // منطقه
            'Z' => 50,  // آدرس کامل API
            'AA' => 10, // ورودی
            'AB' => 15, // شناسه ساختمان
            'AC' => 10, // نام پلاک
            'AD' => 30, // پیام خطا
        ];
    }
}