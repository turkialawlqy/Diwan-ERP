<?php

namespace App\Services\Imports;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Sales\PriceResolver;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Document lines from a spreadsheet (a bill of quantities, a supplier's
 * price offer, a stock count sheet, journal lines). Columns are recognised
 * by Arabic or English names; products are matched by code / barcode, then
 * by name. Nothing is saved: the lines go back to the form for review.
 *
 * Kinds: sales (selling price, free text lines allowed), purchase (purchase
 * price, free text allowed), stock (products only, quantity), journal
 * (account, debit, credit).
 */
class LineImporter
{
    public const MAX_ROWS = 1000;

    public const KINDS = ['sales', 'purchase', 'stock', 'journal'];

    /** Canonical column => accepted header spellings (compared lower-cased, without spaces/punctuation). */
    protected const ALIASES = [
        'code' => ['code', 'sku', 'barcode', 'itemcode', 'productcode', 'رمز', 'الرمز', 'رمزالصنف', 'كود', 'الكود', 'كودالصنف', 'باركود', 'الباركود', 'رقمالصنف'],
        'name' => ['name', 'item', 'itemname', 'product', 'description', 'صنف', 'الصنف', 'اسمالصنف', 'البيان', 'بيان', 'الوصف', 'وصف', 'البند', 'اسمالبند'],
        'description' => ['details', 'notes', 'ملاحظات', 'تفاصيل', 'وصفإضافي'],
        'quantity' => ['quantity', 'qty', 'الكمية', 'كمية', 'الكميةالمعدودة', 'countedquantity'],
        'unit_price' => ['price', 'unitprice', 'rate', 'السعر', 'سعر', 'سعرالوحدة', 'الفئة', 'سعرالافرادي', 'سعرالإفرادي'],
        'discount' => ['discount', 'الخصم', 'خصم'],
        'tax_rate' => ['tax', 'taxrate', 'vat', 'الضريبة', 'نسبةالضريبة', 'ضريبة'],
        'unit' => ['unit', 'uom', 'الوحدة', 'وحدة'],
        'account' => ['account', 'accountcode', 'الحساب', 'رمزالحساب', 'رقمالحساب'],
        'debit' => ['debit', 'dr', 'مدين'],
        'credit' => ['credit', 'cr', 'دائن'],
        'cost_center' => ['costcenter', 'مركزالتكلفة', 'مركزتكلفة'],
    ];

    public function __construct(protected PriceResolver $prices) {}

    /**
     * @return array{lines: array, errors: array, summary: array}
     */
    public function import(UploadedFile $file, string $kind, ?Customer $customer = null): array
    {
        [$header, $rows] = $this->read($file);

        return $kind === 'journal' ? $this->journal($header, $rows) : $this->items($header, $rows, $kind, $customer);
    }

    /** Rows of the first sheet with canonical header keys; data rows keep their spreadsheet line number. */
    public function read(UploadedFile $file): array
    {
        $sheet = Excel::toArray(new class {}, $file)[0] ?? [];
        $headerRow = array_shift($sheet) ?? [];
        $header = [];
        foreach ($headerRow as $i => $title) {
            if ($key = $this->canonical((string) $title)) {
                $header[$i] = $key;
            }
        }

        $rows = [];
        foreach ($sheet as $i => $row) {
            if (! array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== '')) {
                continue;
            }
            $data = [];
            foreach ($header as $index => $key) {
                $value = $row[$index] ?? null;
                $data[$key] = is_string($value) ? trim(ltrim($value, "'")) : $value;
            }
            $rows[$i + 2] = $data;
        }

        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'الملف لا يحتوي بيانات.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'الحد الأقصى '.self::MAX_ROWS.' سطر في الملف الواحد.']);
        }

        return [array_values($header), $rows];
    }

    public function canonical(string $title): ?string
    {
        $normal = mb_strtolower(preg_replace('/[\s_\-\.\/\\\\%:()]+/u', '', preg_replace('/^\xEF\xBB\xBF/', '', $title)));
        $normal = str_replace(['أ', 'إ', 'آ'], 'ا', $normal);
        foreach (self::ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                if ($normal === str_replace(['أ', 'إ', 'آ'], 'ا', $alias)) {
                    return $key;
                }
            }
        }

        return null;
    }

    public function items(array $header, array $rows, string $kind, ?Customer $customer): array
    {
        if (! in_array('code', $header, true) && ! in_array('name', $header, true)) {
            throw ValidationException::withMessages(['file' => 'لم يُعثر على عمود الصنف: أضف عمود «رمز الصنف» أو «الصنف». حمّل القالب للاطلاع على الأعمدة.']);
        }

        // Products of the file in two queries (by code / barcode, then by name).
        $codes = collect($rows)->pluck('code')->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (string) $v)->unique()->values();
        $byCode = Product::where(fn ($q) => $q->whereIn('sku', $codes)->orWhereIn('barcode', $codes))->get();
        $names = collect($rows)->pluck('name')->filter()->map(fn ($v) => (string) $v)->unique()->values();
        $byName = Product::where(fn ($q) => $q->whereIn('name_ar', $names)->orWhereIn('name_en', $names))->get();

        $lines = [];
        $errors = [];
        $matched = $free = 0;
        foreach ($rows as $line => $row) {
            $code = isset($row['code']) && $row['code'] !== '' ? (string) $row['code'] : null;
            $name = isset($row['name']) && $row['name'] !== '' ? (string) $row['name'] : null;
            $quantity = $this->number($row['quantity'] ?? null) ?? 1.0;

            if ($quantity <= 0) {
                $errors[] = "سطر {$line}: الكمية يجب أن تكون أكبر من صفر.";

                continue;
            }

            $product = $code ? $byCode->first(fn ($p) => (string) $p->sku === $code || (string) $p->barcode === $code) : null;
            $product ??= $name ? $byName->first(fn ($p) => $p->name_ar === $name || $p->name_en === $name) : null;

            if (! $product && ($kind === 'stock' || ! $name)) {
                $errors[] = "سطر {$line}: الصنف ".($code ?? $name ?? '').' غير موجود في الأصناف.';

                continue;
            }

            if ($kind === 'stock') {
                $lines[] = ['line' => $line, 'product_id' => $product->id, 'item_name' => $product->name_ar, 'sku' => $product->sku, 'quantity' => $quantity];
                $matched++;

                continue;
            }

            $price = $this->number($row['unit_price'] ?? null);
            if ($price === null && $product) {
                $price = $kind === 'sales'
                    ? $this->prices->price($product, $customer, $quantity)['price']
                    : (float) $product->purchase_price;
            }
            $tax = $this->number($row['tax_rate'] ?? null);
            if ($tax !== null && $tax > 0 && $tax < 1) {
                $tax *= 100; // 0.15 written as a fraction
            }

            $lines[] = [
                'line' => $line,
                'product_id' => $product?->id,
                'item_name' => $product ? ($product->name_ar ?: $name) : $name,
                'description' => $row['description'] ?? ($product && $name && $name !== $product->name_ar ? $name : null),
                'sku' => $product?->sku,
                'quantity' => $quantity,
                'unit_price' => round((float) ($price ?? 0), 4),
                'discount' => max(0, (float) ($this->number($row['discount'] ?? null) ?? 0)),
                'tax_rate' => $tax ?? ($product ? (float) $product->tax_rate : TaxProfile::rate()),
            ];
            $product ? $matched++ : $free++;
        }

        return ['lines' => $lines, 'errors' => $errors, 'summary' => ['matched' => $matched, 'free' => $free, 'errors' => count($errors)]];
    }

    protected function journal(array $header, array $rows): array
    {
        if (! in_array('account', $header, true)) {
            throw ValidationException::withMessages(['file' => 'لم يُعثر على عمود «رمز الحساب».']);
        }
        $accounts = Account::whereIn('code', collect($rows)->pluck('account')->map(fn ($v) => (string) $v))->get()->keyBy(fn ($a) => (string) $a->code);
        $centers = CostCenter::query()->get(['id', 'code', 'name_ar']);

        $lines = [];
        $errors = [];
        foreach ($rows as $line => $row) {
            $code = (string) ($row['account'] ?? '');
            $account = $accounts->get($code);
            $debit = round((float) ($this->number($row['debit'] ?? null) ?? 0), 2);
            $credit = round((float) ($this->number($row['credit'] ?? null) ?? 0), 2);

            if (! $account) {
                $errors[] = "سطر {$line}: الحساب {$code} غير موجود.";
            } elseif ($account->is_parent) {
                $errors[] = "سطر {$line}: الحساب {$code} رئيسي؛ استخدم حساباً فرعياً.";
            } elseif (($debit > 0) === ($credit > 0) || $debit < 0 || $credit < 0) {
                $errors[] = "سطر {$line}: ضع مبلغاً موجباً في المدين أو الدائن فقط.";
            } else {
                $center = isset($row['cost_center']) && $row['cost_center'] !== ''
                    ? $centers->first(fn ($c) => (string) $c->code === (string) $row['cost_center'] || $c->name_ar === (string) $row['cost_center']) : null;
                if (isset($row['cost_center']) && $row['cost_center'] !== '' && ! $center) {
                    $errors[] = "سطر {$line}: مركز التكلفة {$row['cost_center']} غير موجود.";

                    continue;
                }
                $lines[] = ['line' => $line, 'account_id' => $account->id, 'account_code' => $account->code, 'account_name' => $account->name_ar,
                    'debit' => $debit, 'credit' => $credit, 'cost_center_id' => $center?->id, 'description' => $row['name'] ?? null];
            }
        }

        return ['lines' => $lines, 'errors' => $errors, 'summary' => [
            'debit' => round(array_sum(array_column($lines, 'debit')), 2), 'credit' => round(array_sum(array_column($lines, 'credit')), 2), 'errors' => count($errors),
        ]];
    }

    /** A spreadsheet number, tolerating thousands separators and Arabic digits. */
    protected function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $value = strtr((string) $value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => '', ',' => '', '%' => '']);

        return is_numeric(trim($value)) ? (float) trim($value) : null;
    }

    /** Template header and an example row per kind. */
    public static function template(string $kind): array
    {
        return match ($kind) {
            'journal' => [['رمز الحساب', 'مدين', 'دائن', 'مركز التكلفة', 'البيان'], ['5240', '1000', '', '', 'إيجار الشهر'], ['1120', '', '1000', '', 'سداد الإيجار']],
            'stock' => [['رمز الصنف', 'الصنف', 'الكمية'], ['P-001', 'صنف تجريبي', '10']],
            default => [['رمز الصنف', 'الصنف', 'الكمية', 'السعر', 'الخصم', 'الضريبة %'], ['P-001', 'صنف تجريبي', '2', '150', '0', '15'], ['', 'خدمة تركيب (سطر حر)', '1', '500', '', '15']],
        };
    }
}
