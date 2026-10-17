<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>فاتورة ضريبية {{ $invoice->number }}</title>
    <style>
        body { font-family: Tahoma, Arial, sans-serif; color: #0f172a; margin: 0; background: #f8fafc; }
        .page { max-width: 760px; margin: 24px auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 28px; }
        h1 { font-size: 20px; margin: 0 0 4px; } .muted { color: #64748b; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 13px; }
        th, td { padding: 8px; border-bottom: 1px solid #e2e8f0; text-align: right; } th { background: #f1f5f9; }
        .num { direction: ltr; text-align: left; font-family: monospace; }
        .grid { display: flex; gap: 16px; justify-content: space-between; flex-wrap: wrap; margin-top: 16px; font-size: 13px; }
        .box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px; flex: 1; min-width: 220px; }
        .total td { font-weight: bold; background: #eef2ff; }
        .actions { text-align: center; margin: 16px; } button { padding: 8px 20px; border-radius: 8px; border: 0; background: #4f46e5; color: #fff; cursor: pointer; }
        @media print { .actions { display: none; } body { background: #fff; } .page { border: 0; margin: 0; } }
    </style>
</head>
<body>
<div class="page">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
        <div>
            <h1>فاتورة ضريبية — Tax Invoice</h1>
            <div class="muted">رقم الفاتورة: <span class="num">{{ $invoice->number }}</span> · تاريخ الإصدار: <span class="num">{{ ($invoice->issued_at ?? $invoice->created_at)->format('Y-m-d H:i') }}</span></div>
        </div>
        @if ($qr)
            <img src="{{ $qr }}" alt="QR" width="120" height="120">
        @endif
    </div>

    <div class="grid">
        <div class="box">
            <strong>المورّد</strong><br>
            {{ $platform['name'] }}<br>
            @if ($platform['tax_number']) الرقم الضريبي: <span class="num">{{ $platform['tax_number'] }}</span><br> @endif
            @if ($platform['commercial_registration']) السجل التجاري: <span class="num">{{ $platform['commercial_registration'] }}</span><br> @endif
            {{ $platform['address'] }}
        </div>
        <div class="box">
            <strong>العميل</strong><br>
            {{ $invoice->company->name }}<br>
            @if ($invoice->company->tax_number) الرقم الضريبي: <span class="num">{{ $invoice->company->tax_number }}</span><br> @endif
            {{ $invoice->company->address }}
        </div>
    </div>

    <table>
        <thead><tr><th>البيان</th><th>الفترة</th><th class="num">المبلغ</th></tr></thead>
        <tbody>
            <tr>
                <td>اشتراك {{ $invoice->plan?->name_ar }} ({{ $invoice->billing_cycle === 'yearly' ? 'سنوي' : 'شهري' }})</td>
                <td class="num">{{ $invoice->period_start->format('Y-m-d') }} → {{ $invoice->period_end->format('Y-m-d') }}</td>
                <td class="num">{{ number_format((float) $invoice->amount, 2) }}</td>
            </tr>
            <tr><td colspan="2">ضريبة القيمة المضافة 15%</td><td class="num">{{ number_format((float) $invoice->vat_amount, 2) }}</td></tr>
            <tr class="total"><td colspan="2">الإجمالي شامل الضريبة (ر.س)</td><td class="num">{{ number_format((float) $invoice->total, 2) }}</td></tr>
        </tbody>
    </table>

    <div class="grid">
        <div class="box">
            <strong>الحالة:</strong> {{ ['paid' => 'مسددة', 'unpaid' => 'بانتظار السداد', 'void' => 'ملغاة'][$invoice->status] ?? $invoice->status }}
            @if ($invoice->paid_at) · <span class="num">{{ $invoice->paid_at->format('Y-m-d') }}</span> @endif
        </div>
        @if ($invoice->status === 'unpaid' && $platform['iban'])
            <div class="box">
                <strong>السداد بالتحويل البنكي</strong><br>
                {{ $platform['bank_name'] }} — {{ $platform['account_name'] ?: $platform['name'] }}<br>
                IBAN: <span class="num">{{ $platform['iban'] }}</span><br>
                <span class="muted">اذكر رقم الفاتورة {{ $invoice->number }} في بيان التحويل.</span>
            </div>
        @endif
    </div>
</div>
<div class="actions"><button onclick="window.print()">طباعة / حفظ PDF</button></div>
</body>
</html>
