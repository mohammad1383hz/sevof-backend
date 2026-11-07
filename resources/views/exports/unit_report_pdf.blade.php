<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8"/>
    <style>
        * {
            font-family: DejaVu Sans, sans-serif;
            box-sizing: border-box;
        }

        body {
            direction: rtl;
            unicode-bidi: bidi-override;
            font-size: 11px;
            color: #333;
            margin: 20px;
        }

        h1 {
            text-align: center;
            color: #1F4E79;
            font-size: 15px;
            border-bottom: 2px solid #1F4E79;
            padding-bottom: 8px;
            margin-bottom: 16px;
        }

        h2 {
            font-size: 12px;
            padding: 6px 10px;
            border-radius: 4px;
            margin-top: 20px;
            margin-bottom: 8px;
        }

        h2.approved { background: #1E7E34; color: #fff; }
        h2.rejected  { background: #C0392B; color: #fff; }
        h2.summary   { background: #1F4E79; color: #fff; }

        .info-box {
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 16px;
            background: #f9f9f9;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table td {
            padding: 4px 8px;
            font-size: 11px;
        }

        .info-table td.lbl {
            font-weight: bold;
            color: #1F4E79;
            width: 20%;
            white-space: nowrap;
        }

        .info-table td.val {
            width: 30%;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            margin-bottom: 14px;
        }

        table.data th {
            background: #2c3e50;
            color: #fff;
            padding: 6px 8px;
            text-align: right;
            font-size: 10px;
            white-space: nowrap;
        }

        table.data td {
            padding: 5px 8px;
            border-bottom: 1px solid #eee;
            font-size: 10px;
            text-align: right;
        }

        table.data tr:nth-child(even) td {
            background: #f8f8f8;
        }

        table.data td.center {
            text-align: center;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 8px;
            font-size: 9px;
            color: #fff;
        }

        .badge-approved { background: #1E7E34; }
        .badge-rejected  { background: #C0392B; }

        .summary-row td {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            padding: 8px;
        }

        .c-total    { color: #1F4E79; }
        .c-approved { color: #1E7E34; }
        .c-rejected  { color: #C0392B; }
        .c-pending  { color: #E67E22; }

        .empty {
            color: #999;
            padding: 10px;
            font-size: 10px;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 9px;
            color: #aaa;
            border-top: 1px solid #eee;
            padding-top: 8px;
        }
    </style>
</head>
<body>

<h1>{{ $unitInfo['VahedName'] ?? '' }} - گزارش محموله مدارک</h1>

<div class="info-box">
    <table class="info-table">
        <tr>
            <td class="lbl">کد واحد:</td>
            <td class="val">{{ $unitInfo['CodeVahed'] ?? '-' }}</td>
            <td class="lbl">نام واحد:</td>
            <td class="val">{{ $unitInfo['VahedName'] ?? '-' }}</td>
        </tr>
        <tr>
            <td class="lbl">شماره نامه:</td>
            <td class="val">{{ $unitInfo['Mahmoleh_Letter_No'] ?? '-' }}</td>
            <td class="lbl">تاریخ نامه:</td>
            <td class="val">{{ $unitInfo['Mahmoleh_Letter_Date'] ?? '-' }}</td>
        </tr>
    </table>
</div>

<h2 class="summary">خلاصه آماری</h2>
<table class="data">
    <thead>
        <tr>
            <th>تعداد کل</th>
            <th>تایید شده</th>
            <th>رد شده</th>
            <th>در انتظار</th>
        </tr>
    </thead>
    <tbody>
        <tr class="summary-row">
            <td class="c-total">{{ $total }}</td>
            <td class="c-approved">{{ $approved->count() }}</td>
            <td class="c-rejected">{{ $rejected->count() }}</td>
            <td class="c-pending">{{ $total - $approved->count() - $rejected->count() }}</td>
        </tr>
    </tbody>
</table>

<h2 class="approved">تایید شده ({{ $approved->count() }} نفر)</h2>
@if($approved->count() > 0)
<table class="data">
    <thead>
        <tr>
            <th style="width:5%; text-align:center">#</th>
            <th style="width:15%">نام</th>
            <th style="width:20%">نام خانوادگی</th>
            <th style="width:22%">مقطع تحصیلی</th>
            <th style="width:20%">شماره دانشجویی</th>
            <th style="width:12%; text-align:center">وضعیت</th>
        </tr>
    </thead>
    <tbody>
        @foreach($approved as $index => $student)
        <tr>
            <td class="center">{{ $index + 1 }}</td>
            <td>{{ $student->Name ?? '-' }}</td>
            <td>{{ $student->Family ?? '-' }}</td>
            <td>{{ $student->Maghta_Tahsili ?? '-' }}</td>
            <td>{{ $student->StudentNo ?? '-' }}</td>
            <td class="center"><span class="badge badge-approved">تایید</span></td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<p class="empty">موردی یافت نشد</p>
@endif

<h2 class="rejected">رد شده ({{ $rejected->count() }} نفر)</h2>
@if($rejected->count() > 0)
<table class="data">
    <thead>
        <tr>
            <th style="width:4%; text-align:center">#</th>
            <th style="width:12%">نام</th>
            <th style="width:16%">نام خانوادگی</th>
            <th style="width:16%">مقطع تحصیلی</th>
            <th style="width:14%">شماره دانشجویی</th>
            <th style="width:10%; text-align:center">وضعیت</th>
            <th style="width:28%">دلیل رد</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rejected as $index => $student)
        <tr>
            <td class="center">{{ $index + 1 }}</td>
            <td>{{ $student->Name ?? '-' }}</td>
            <td>{{ $student->Family ?? '-' }}</td>
            <td>{{ $student->Maghta_Tahsili ?? '-' }}</td>
            <td>{{ $student->StudentNo ?? '-' }}</td>
            <td class="center"><span class="badge badge-rejected">رد</span></td>
            <td>{{ $student->status_resones ?? '-' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<p class="empty">موردی یافت نشد</p>
@endif

<div class="footer">
    تاریخ تولید گزارش: {{ now()->format('Y-m-d H:i') }}
</div>

</body>
</html>