<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReconciliationController extends Controller
{
    public function compareTrans(Request $request)
    {
     $polcardPath = storage_path('app/public/polcard_trans.csv');
$cardPath    = storage_path('app/public/card_trans.csv');
        // خوندن polcard_trans و ساختن یک set از reference_number ها (برای سرعت جستجو با isset)
        $polcardRefs = $this->getColumnValues($polcardPath, 'reference_number');

        // خوندن card_trans به صورت کامل (تمام ستون‌ها نگه داشته میشه)
        $cardRows = $this->readCsvAsArray($cardPath);

        // پیدا کردن ردیف‌هایی که reference_number شون تو polcard نیست
        $missingInPolcard = [];

        foreach ($cardRows as $row) {
            $ref = $row['reference_number'] ?? null;

            if ($ref !== null && !isset($polcardRefs[$ref])) {
                $missingInPolcard[] = $row;
            }
        }

        return response()->json([
            'total_card_trans'       => count($cardRows),
            'total_polcard_trans'    => count($polcardRefs),
            'missing_count'          => count($missingInPolcard),
            'missing_records'        => $missingInPolcard,
        ]);
    }

    /**
     * خواندن یک ستون خاص از CSV و برگردوندن به صورت array با key = مقدار (برای جستجوی سریع O(1))
     */
    private function getColumnValues(string $filePath, string $columnName): array
    {
        $result = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            abort(404, "File not found: $filePath");
        }

        $header = fgetcsv($handle);
        $header = array_map('trim', $header);
        $colIndex = array_search($columnName, $header);

        if ($colIndex === false) {
            fclose($handle);
            abort(400, "Column $columnName not found in $filePath");
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[$colIndex])) {
                $value = trim($row[$colIndex]);
                $result[$value] = true; // فقط برای جستجوی سریع
            }
        }

        fclose($handle);
        return $result;
    }

    /**
     * خواندن کل CSV به صورت آرایه‌ای از رکوردهای associative (بر اساس هدر)
     */
    private function readCsvAsArray(string $filePath): array
    {
        $result = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            abort(404, "File not found: $filePath");
        }

        $header = fgetcsv($handle);
        $header = array_map('trim', $header);

        while (($row = fgetcsv($handle)) !== false) {
            // اگر تعداد ستون‌های ردیف با هدر برابر نبود، رد کن (خط خراب)
            if (count($row) !== count($header)) {
                continue;
            }

            $row = array_map('trim', $row);
            $result[] = array_combine($header, $row);
        }

        fclose($handle);
        return $result;
    }
}
