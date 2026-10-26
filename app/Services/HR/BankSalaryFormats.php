<?php

namespace App\Services\HR;

/**
 * Salary upload layouts of the main Saudi banks. Each layout is a header
 * record and one detail record per employee; a token in "header" /
 * "columns" is a field name (see field()) or else written literally (the
 * record type markers). Banks revise their layouts and some agree a
 * customer-specific one: confirm the generated file with the bank on the
 * first upload and adjust the layout here if needed.
 */
class BankSalaryFormats
{
    public const FORMATS = [
        'alrajhi' => [
            'label' => 'مصرف الراجحي',
            'delimiter' => ',', 'date' => 'Ymd',
            'header' => ['H', 'mol', 'company_iban', 'value_date', 'count', 'total'],
            'columns' => ['D', 'iban', 'name', 'national_id', 'bank_code', 'net', 'basic', 'housing', 'other', 'deductions', 'reference'],
        ],
        'snb' => [
            'label' => 'البنك الأهلي السعودي',
            'delimiter' => ',', 'date' => 'd/m/Y',
            'header' => ['company_iban', 'mol', 'value_date', 'total', 'count'],
            'columns' => ['national_id', 'name', 'iban', 'bank_code', 'net', 'basic', 'housing', 'other', 'deductions'],
        ],
        'riyad' => [
            'label' => 'بنك الرياض',
            'delimiter' => ';', 'date' => 'Y-m-d',
            'header' => ['HDR', 'company_iban', 'value_date', 'count', 'total', 'mol'],
            'columns' => ['DTL', 'national_id', 'name', 'bank_code', 'iban', 'basic', 'housing', 'other', 'deductions', 'net'],
        ],
        'alinma' => [
            'label' => 'مصرف الإنماء',
            'delimiter' => ',', 'date' => 'Ymd',
            'header' => ['111', 'mol', 'company_iban', 'total', 'count', 'value_date'],
            'columns' => ['112', 'iban', 'name', 'national_id', 'net', 'basic', 'housing', 'other', 'deductions', 'bank_code'],
        ],
    ];

    /** Saudi IBAN: SA + 22 digits, ISO 13616 mod-97 check. */
    public static function validIban(?string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', (string) $iban));
        if (! preg_match('/^SA\d{22}$/', $iban)) {
            return false;
        }

        $digits = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    /** SAMA bank identifier held in a Saudi IBAN (positions 5-6). */
    public static function bankCode(?string $iban): ?string
    {
        $iban = strtoupper(str_replace(' ', '', (string) $iban));

        return str_starts_with($iban, 'SA') && strlen($iban) >= 6 ? substr($iban, 4, 2) : null;
    }

    /**
     * @param  array  $context  mol, gosi, company_iban, value_date (Carbon), reference
     * @param  array  $rows  normalised employee rows (see PayrollService::wpsRows)
     */
    public function render(string $format, array $context, array $rows): string
    {
        $layout = self::FORMATS[$format] ?? self::FORMATS['alrajhi'];
        $context += [
            'count' => (string) count($rows),
            'total' => $this->amount(array_sum(array_column($rows, 'net'))),
            'value_date' => now(),
        ];
        $context['value_date'] = $context['value_date']->format($layout['date']);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $this->record($layout['header'], $context), $layout['delimiter']);
        foreach ($rows as $row) {
            fputcsv($out, $this->record($layout['columns'], $row + $context), $layout['delimiter']);
        }
        rewind($out);

        return stream_get_contents($out);
    }

    protected function record(array $tokens, array $values): array
    {
        return array_map(function ($token) use ($values) {
            if (! array_key_exists($token, $values)) {
                return $token;
            }
            $value = $values[$token];

            return is_float($value) || is_int($value) ? $this->amount($value) : $this->safe((string) $value);
        }, $tokens);
    }

    protected function amount(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** Neutralise spreadsheet formulas in text cells. */
    protected function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r|]/', $value) ? "'".$value : $value;
    }
}
