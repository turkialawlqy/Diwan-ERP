<html dir="rtl" lang="ar">
<head><meta charset="utf-8">@include('pdf._styles')</head>
<body>
@include('pdf._header')

<table>
    <tr>
        <td class="box" style="width: 100%; vertical-align: top;">
            <table class="meta">
                @foreach($meta as $label => $value)
                    <tr><td class="muted small" style="width: 28%;">{{ $label }}</td><td class="bold">{{ $value }}</td></tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

@if(!empty($body))
    <div style="margin-top: 12pt; line-height: 1.9; font-size: 10.5pt;">{!! nl2br(e($body)) !!}</div>
@endif

@if(!empty($rows))
    <table class="lines" style="margin-top: 12pt;">
        <thead><tr>@foreach($columns as $col)<th style="text-align: right;">{{ $col }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach($rows as $row)
                <tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @endforeach
        </tbody>
    </table>
@endif

@if(!empty($signatures))
    <table style="margin-top: 36pt;">
        <tr>@foreach($signatures as $sig)<td style="text-align: center; width: {{ floor(100 / count($signatures)) }}%;"><div style="border-top: 0.6pt dotted #64748b; margin: 0 14pt; padding-top: 4pt;" class="small muted">{{ $sig }}</div></td>@endforeach</tr>
    </table>
@endif

<htmlpagefooter name="pagefooter"><div class="small muted" style="text-align: center;">{{ $company['name'] }} — {{ $number }} — صفحة {PAGENO} من {nbpg}</div></htmlpagefooter>
<sethtmlpagefooter name="pagefooter" value="on" />
</body>
</html>
