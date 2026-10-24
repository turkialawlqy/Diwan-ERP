<html dir="rtl" lang="ar">
<head><meta charset="utf-8">@include('pdf._styles')</head>
<body>
@include('pdf._header')

<table>
    <tr>
        <td class="box" style="width: 58%; vertical-align: top;">
                <div class="box-title">العميل</div>
                <div class="bold">{{ $customer['name'] }}</div>
                @if($customer['tax_number'])<div class="small">الرقم الضريبي: <span class="num">{{ $customer['tax_number'] }}</span></div>@endif
                @if($customer['phone'])<div class="small muted">جوال: <span class="num">{{ $customer['phone'] }}</span></div>@endif
            </td>
        <td style="width: 2%;"></td>
        <td class="box" style="width: 40%; vertical-align: top;">
                <table class="meta">
                    <tr><td class="muted small">الفترة</td><td class="bold">@if($from)<span class="num">{{ $from }}</span>@else من البداية @endif — <span class="num">{{ $to }}</span></td></tr>
                    <tr><td class="muted small">الرصيد الافتتاحي</td><td class="num bold">{{ number_format($opening, 2) }}</td></tr>
                    <tr><td class="muted small">الرصيد الختامي</td><td class="num bold" style="color: {{ $color }};">{{ number_format($closing, 2) }}</td></tr>
                </table>
            </td>
    </tr>
</table>

<table class="lines" style="margin-top: 10pt;">
    <thead>
        <tr>
            <th style="width: 13%;">التاريخ</th>
            <th style="width: 15%;">النوع</th>
            <th style="width: 15%;">المرجع</th>
            <th style="text-align: right;">البيان</th>
            <th style="width: 12%;">مدين</th>
            <th style="width: 12%;">دائن</th>
            <th style="width: 13%;">الرصيد</th>
        </tr>
    </thead>
    <tbody>
        @foreach($transactions as $row)
            <tr>
                <td class="num" style="text-align: center;">{{ $row['date'] }}</td>
                <td>{{ $row['type_ar'] }}</td>
                <td class="num" style="text-align: center;">{{ $row['reference'] }}</td>
                <td class="small">{{ $row['notes'] }}</td>
                <td class="num">{{ $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                <td class="num">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                <td class="num bold">{{ number_format($row['balance'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="words small" style="margin-top: 10pt;">
    <span class="bold">الرصيد المستحق:</span> {{ number_format($closing, 2) }} {{ \App\Support\CurrencyNames::for(\App\Models\Company::find($company['id'] ?? null)?->currency ?? app(\App\Services\TenantManager::class)->getCompany()?->currency)[2] }} — {{ \App\Services\Documents\DocumentPdf::words(abs($closing)) }}{{ $closing < 0 ? ' (رصيد دائن للعميل)' : '' }}
</div>
@if($footer_text)<div class="footer">{{ $footer_text }}</div>@endif
<htmlpagefooter name="pagefooter"><div class="small muted" style="text-align: center;">{{ $company['name'] }} — كشف حساب — صفحة {PAGENO} من {nbpg}</div></htmlpagefooter>
<sethtmlpagefooter name="pagefooter" value="on" />
</body>
</html>
