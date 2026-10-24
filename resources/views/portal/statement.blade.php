<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>كشف حساب — {{ $company->name }}</title>
    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --brand: #4f46e5; --bg: #f1f5f9; --card: #ffffff; --ok: #047857; --due: #b45309; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font-family: Tahoma, "Segoe UI", Arial, sans-serif; font-size: 14px; }
        .wrap { max-width: 920px; margin: 0 auto; padding: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 18px; margin-bottom: 14px; }
        .head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .head img { max-height: 56px; max-width: 180px; }
        h1 { font-size: 18px; margin: 0; } h2 { font-size: 15px; margin: 0 0 12px; }
        .muted { color: var(--muted); font-size: 12px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; }
        .stat { background: var(--bg); border-radius: 10px; padding: 12px; } .stat b { display: block; font-size: 18px; margin-top: 4px; direction: ltr; text-align: right; }
        .btn { display: inline-block; background: var(--brand); color: #fff; text-decoration: none; padding: 9px 14px; border-radius: 9px; font-size: 13px; }
        .table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table th { text-align: right; color: var(--muted); font-weight: normal; border-bottom: 1px solid var(--line); padding: 8px 6px; }
        .table td { border-bottom: 1px solid var(--line); padding: 8px 6px; }
        .num { direction: ltr; text-align: left; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .scroll { overflow-x: auto; }
        .tag { font-size: 11px; padding: 2px 8px; border-radius: 99px; background: var(--bg); }
        .tag.paid { color: var(--ok); } .tag.unpaid, .tag.partial { color: var(--due); }
        a.link { color: var(--brand); }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card head">
        <div>
            <h1>{{ $company->name }}</h1>
            <div class="muted">كشف حساب: {{ $customer->company_name ?: $customer->name }} @if($company->tax_number) · الرقم الضريبي للمنشأة <span class="num">{{ $company->tax_number }}</span>@endif</div>
        </div>
        @if($logo)<img src="{{ $logo }}" alt="{{ $company->name }}">@endif
    </div>

    <div class="card">
        <div class="stats">
            <div class="stat"><span class="muted">الرصيد المستحق</span><b>{{ number_format($statement['closing'], 2) }} {{ \App\Support\CurrencyNames::for($company->currency)[2] }}</b></div>
            <div class="stat"><span class="muted">عدد الفواتير</span><b>{{ $invoices->count() }}</b></div>
            <div class="stat"><span class="muted">فواتير غير مسددة</span><b>{{ $invoices->where('remaining_amount', '>', 0)->count() }}</b></div>
        </div>
        <p style="margin: 14px 0 0;"><a class="btn" href="{{ $statementPdf }}">تنزيل كشف الحساب PDF</a></p>
    </div>

    <div class="card">
        <h2>الفواتير</h2>
        <div class="scroll">
            <table class="table">
                <thead><tr><th>الرقم</th><th>التاريخ</th><th>الاستحقاق</th><th>الإجمالي</th><th>المتبقي</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                @forelse($invoices as $inv)
                    <tr>
                        <td class="num">{{ $inv->invoice_number }}</td>
                        <td class="num">{{ $inv->invoice_date?->format('Y-m-d') }}</td>
                        <td class="num">{{ $inv->due_date?->format('Y-m-d') ?? '—' }}</td>
                        <td class="num">{{ number_format($inv->total, 2) }}</td>
                        <td class="num">{{ number_format($inv->remaining_amount, 2) }}</td>
                        <td><span class="tag {{ $inv->payment_status }}">{{ ['paid' => 'مسددة', 'partial' => 'مسددة جزئياً', 'unpaid' => 'غير مسددة'][$inv->payment_status] ?? $inv->payment_status }}</span></td>
                        <td><a class="link" href="{{ $inv->pdf_url }}">PDF</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">لا توجد فواتير</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>الحركات</h2>
        <div class="scroll">
            <table class="table">
                <thead><tr><th>التاريخ</th><th>البيان</th><th>المرجع</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
                <tbody>
                @foreach($statement['transactions'] as $row)
                    <tr>
                        <td class="num">{{ $row['date'] }}</td>
                        <td>{{ $row['type_ar'] }}</td>
                        <td class="num">{{ $row['reference'] }}</td>
                        <td class="num">{{ $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                        <td class="num">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                        <td class="num"><b>{{ number_format($row['balance'], 2) }}</b></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <p class="muted" style="text-align: center;">هذا الرابط خاص بكم ومؤقت. للاستفسار تواصلوا مع {{ $company->name }}@if($company->phone) على <span class="num">{{ $company->phone }}</span>@endif.</p>
</div>
</body>
</html>
