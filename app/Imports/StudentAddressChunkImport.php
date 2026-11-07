<?php

namespace App\Imports;

use Maatwebsite\Excel\Row;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class StudentAddressChunkImport implements OnEachRow, WithHeadingRow, WithChunkReading
{
    public $rows = [];

    public function onRow(Row $row)
    {
        $r = $row->toArray();

        $this->rows[] = [
            'student_number' => $r['student_number'] ?? '',
            'national_id' => $r['national_id'] ?? '',
            'first_name' => $r['first_name'] ?? '',
            'last_name' => $r['last_name'] ?? '',
            'postal_code' => $r['postalcode'] ?? '',
            'address' => $r['address'] ?? '',
            'mobile' => $r['mobile'] ?? '',
            'birthdate' => $r['birthdate'] ?? '',
        ];
    }

    public function chunkSize(): int
    {
        return 2000;
    }
}
                                                                                        