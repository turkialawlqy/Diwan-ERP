@php
    // Colours reach CSS: only #rrggbb values pass (no CSS injection from template settings).
    $safe = fn ($c, $d) => \App\Services\Documents\FreeformLayout::color($c) ?? $d;
    $color = $safe($color ?? null, '#0f172a');
@endphp
<style>
    body { font-size: 10pt; color: #0f172a; }
    .muted { color: #64748b; }
    .small { font-size: 8.5pt; }
    .bold { font-weight: bold; }
    .num { direction: ltr; text-align: left; font-family: dejavusanscondensed; }
    table { border-collapse: collapse; width: 100%; }
    .header td { vertical-align: middle; }
    .title { font-size: 17pt; font-weight: bold; color: {{ $color }}; }
    .title-en { font-size: 9pt; color: #64748b; font-family: dejavusanscondensed; }
    .box { border: 0.6pt solid #cbd5e1; padding: 6pt 8pt; }
    .box-title { font-weight: bold; color: {{ $color }}; padding-bottom: 3pt; }
    .meta td { padding: 2pt 4pt; }
    .lines th { background: {{ $safe($layout['table']['header_bg'] ?? null, '#f1f5f9') }}; color: {{ $safe($layout['table']['header_text_color'] ?? null, '#0f172a') }}; padding: 5pt 4pt; font-size: 9pt; border-bottom: 1pt solid {{ $color }}; }
    .lines td { padding: 5pt 4pt; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
    .totals td { padding: 3.5pt 6pt; }
    .totals .grand td { background: {{ $color }}; color: #ffffff; font-weight: bold; font-size: 11pt; }
    .words { background: #f8fafc; border: 0.6pt dashed #cbd5e1; padding: 5pt 8pt; margin-top: 6pt; }
    .footer { border-top: 0.6pt solid #e2e8f0; margin-top: 10pt; padding-top: 5pt; text-align: center; color: #64748b; font-size: 8.5pt; }
</style>
