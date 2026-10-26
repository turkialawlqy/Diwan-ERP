<html dir="rtl" lang="ar">
<head><meta charset="utf-8">@include('pdf._styles')</head>
<body>
@include('pdf._header')

<table>
    <tr>
        <td class="box" style="width: 56%; vertical-align: top;">
                <div class="box-title">بيانات العميل</div>
                @if(!empty($customer))
                    <div class="bold">{{ $customer['name'] }}</div>
                    @if($customer['contact'])<div class="small">{{ $customer['contact'] }}</div>@endif
                    @if($customer['tax_number'])<div class="small">الرقم الضريبي: <span class="num">{{ $customer['tax_number'] }}</span></div>@endif
                    @if($customer['cr'])<div class="small">السجل التجاري: <span class="num">{{ $customer['cr'] }}</span></div>@endif
                    @if($customer['address'])<div class="small muted">{{ $customer['address'] }}</div>@endif
                    @if($customer['phone'])<div class="small muted">جوال: <span class="num">{{ $customer['phone'] }}</span></div>@endif
                @else
                    <div class="muted">عميل نقدي</div>
                @endif
            </td>
        <td style="width: 2%;"></td>
        <td class="box" style="width: 42%; vertical-align: top;">
                <table class="meta">
                    @foreach($meta as $label => $value)
                        <tr><td class="muted small" style="width: 42%;">{{ $label }}</td><td class="bold">{{ $value }}</td></tr>
                    @endforeach
                </table>
            </td>
    </tr>
</table>

<table class="lines" style="margin-top: 10pt;">
    <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th style="text-align: right;">البيان</th>
            <th style="width: 9%;">الكمية</th>
            <th style="width: 13%;">سعر الوحدة</th>
            <th style="width: 9%;">الضريبة %</th>
            <th style="width: 12%;">الضريبة</th>
            <th style="width: 15%;">الإجمالي</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $i => $line)
            <tr>
                <td style="text-align: center;" class="muted">{{ $i + 1 }}</td>
                <td>
                    <div class="bold">{{ $line['name'] }}</div>
                    @if(!empty($line['description']))<div class="small muted">{{ $line['description'] }}</div>@endif
                </td>
                <td class="num" style="text-align: center;">{{ $line['quantity'] }}</td>
                <td class="num">{{ number_format($line['unit_price'], 2) }}</td>
                <td class="num" style="text-align: center;">{{ rtrim(rtrim(number_format($line['tax_rate'], 2), '0'), '.') }}</td>
                <td class="num">{{ number_format($line['tax_amount'], 2) }}</td>
                <td class="num bold">{{ number_format($line['total'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@php
    $grand = collect($totals)->first(fn ($row) => ! empty($row[2]));
@endphp
<table style="margin-top: 10pt;">
    <tr>
        <td style="width: 55%; vertical-align: top; padding-left: 10pt;">
            @if(($layout['footer']['show_tafqeet'] ?? true) && $grand)
                <div class="words small"><span class="bold">المبلغ كتابةً:</span> {{ \App\Services\Documents\DocumentPdf::words($grand[1], $currency_words) }}</div>
            @endif
            @if($qr && ($layout['qr']['show'] ?? true))
                <div style="margin-top: 8pt;">
                    <barcode code="{{ $qr }}" type="QR" size="1.05" error="M" disableborder="1" />
                    <div class="small muted">رمز الاستجابة السريعة — هيئة الزكاة والضريبة والجمارك</div>
                </div>
            @endif
        </td>
        <td style="width: 45%; vertical-align: top;">
            <table class="totals box" style="padding: 0;">
                @foreach($totals as $row)
                    <tr class="{{ !empty($row[2]) ? 'grand' : '' }}">
                        <td>{{ $row[0] }}</td>
                        <td class="num">{{ number_format($row[1], 2) }} <span style="font-family: xbriyaz;">{{ $currency_label }}</span></td>
                    </tr>
                @endforeach
            </table>
            @if($base_equivalent)
                <div class="small muted" style="margin-top: 4pt;">
                    سعر الصرف: <span class="num">1 {{ $currency_label }} = {{ rtrim(rtrim(number_format($base_equivalent['rate'], 6, '.', ''), '0'), '.') }} {{ $base_currency }}</span><br>
                    الإجمالي بالعملة الأساسية: <span class="num">{{ number_format($base_equivalent['total'], 2) }} {{ $base_currency }}</span> — الضريبة: <span class="num">{{ number_format($base_equivalent['tax'], 2) }} {{ $base_currency }}</span>
                </div>
            @endif
        </td>
    </tr>
</table>

@if(!empty($notes) && ($layout['footer']['show_notes'] ?? true))
    <div style="margin-top: 10pt;" class="small"><span class="bold">ملاحظات:</span> {{ $notes }}</div>
@endif
@php $terms = $terms ?: $template_terms; @endphp
@if($terms && ($layout['footer']['show_terms'] ?? true))
    <div style="margin-top: 4pt;" class="small"><span class="bold">الشروط والأحكام:</span> {{ $terms }}</div>
@endif

@if($footer_text)
    <div class="footer">{{ $footer_text }}</div>
@endif
<htmlpagefooter name="pagefooter"><div class="small muted" style="text-align: center;">{{ $company['name'] }} — {{ $number }} — صفحة {PAGENO} من {nbpg}</div></htmlpagefooter>
<sethtmlpagefooter name="pagefooter" value="on" />
</body>
</html>
