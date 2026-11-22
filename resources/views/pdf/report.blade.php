<html dir="rtl" lang="ar">
<head><meta charset="utf-8">
<style>
    body { font-size: 9.5pt; color: #0f172a; }
    .num { direction: ltr; text-align: left; font-family: dejavusanscondensed; }
    table { border-collapse: collapse; width: 100%; }
    th { background: #f1f5f9; padding: 5pt 4pt; border-bottom: 1pt solid #0f172a; font-size: 9pt; }
    td { padding: 4pt; border-bottom: 0.5pt solid #e2e8f0; }
    .b td { font-weight: bold; background: #f8fafc; }
    .muted { color: #64748b; }
</style></head>
<body>
<table><tr>
    <td style="border: 0;"><div style="font-size: 12pt; font-weight: bold;">{{ $companyName }}</div>@if($taxNumber)<div class="muted">الرقم الضريبي: <span class="num">{{ $taxNumber }}</span></div>@endif</td>
    <td style="border: 0; text-align: center;"><div style="font-size: 16pt; font-weight: bold;">{{ $table['title'] }}</div><div class="muted">{{ $table['subtitle'] }}</div></td>
    <td style="border: 0; text-align: left;" class="muted">{{ now()->format('Y-m-d H:i') }}</td>
</tr></table>
{{-- A long report is several tables of at most 300 rows (header repeated): one huge table makes mPDF hold it all in memory. --}}
@foreach(array_chunk($table['rows'], 300, true) as $chunk)
<table style="margin-top: 10pt;">
    <thead><tr>@foreach($table['columns'] as $c)<th style="{{ $c['type'] === 'money' ? 'text-align: left;' : 'text-align: right;' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach($chunk as $i => $row)
        <tr class="{{ in_array($i, $table['bold'] ?? [], true) ? 'b' : '' }}">
            @foreach($table['columns'] as $c)
                @php $v = $row[$c['key']] ?? null; @endphp
                @if($c['type'] === 'money')
                    <td class="num">{{ $v === null || $v === '' ? '' : number_format((float) $v, 2) }}</td>
                @else
                    <td>{{ $v }}</td>
                @endif
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
@endforeach
</body></html>
