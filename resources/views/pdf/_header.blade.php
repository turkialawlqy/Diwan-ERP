<table class="header">
    <tr>
        <td style="width: 38%;">
            <div class="bold" style="font-size: 12pt;">{{ $company['name'] }}</div>
            @if($company['tax_number'])<div class="small">الرقم الضريبي: <span class="num">{{ $company['tax_number'] }}</span></div>@endif
            @if($company['cr'])<div class="small">السجل التجاري: <span class="num">{{ $company['cr'] }}</span></div>@endif
            @if($company['address'])<div class="small muted">{{ $company['address'] }}</div>@endif
            @if($company['phone'])<div class="small muted">هاتف: <span class="num">{{ $company['phone'] }}</span></div>@endif
        </td>
        <td style="width: 30%; text-align: center;">
            <div class="title">{{ $title }}</div>
            @isset($title_en)<div class="title-en">{{ $title_en }}</div>@endisset
        </td>
        <td style="width: 32%; text-align: left;">
            @if($logo)
                <img src="{{ $logo }}" style="max-height: {{ min((int) ($layout['logo']['height'] ?? 70), 90) }}px; max-width: 200px;" />
            @endif
        </td>
    </tr>
</table>
<div style="border-bottom: 1.5pt solid {{ $color }}; margin: 6pt 0 8pt;"></div>
