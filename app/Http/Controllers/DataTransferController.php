<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
use App\Services\Inventory\DocumentStock;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk import (CSV / Excel) and streaming CSV export of master data, and the
 * opening balances of a company moving onto the system (party balances,
 * opening stock and the opening trial balance).
 */
class DataTransferController extends Controller
{
    public const MAX_IMPORT_ROWS = 5000;

    /** Import-only columns that post an opening balance instead of being stored as-is. */
    protected const OPENING_COLUMNS = ['opening_balance', 'opening_quantity', 'opening_cost', 'warehouse_code'];

    /**
     * Accounts whose balance is the sum of a sub-ledger (customers, suppliers,
     * stock): their opening balances come from those imports so the
     * sub-ledger and the account agree.
     */
    protected const CONTROL_ROLES = ['receivables' => 'العملاء', 'payables' => 'الموردون', 'inventory' => 'الأصناف', 'grni' => 'الأصناف'];

    protected function definitions(): array
    {
        return [
            'customers' => [
                'label' => 'العملاء', 'model' => Customer::class, 'permission' => ['view' => 'sales.clients.view', 'create' => 'sales.clients.create'],
                'columns' => ['code', 'name', 'company_name', 'customer_type', 'tax_number', 'commercial_registration', 'phone', 'email', 'street_name', 'building_number', 'district', 'city', 'postal_code', 'credit_limit', 'opening_balance'],
                'rules' => ['name' => 'required|string|max:255', 'customer_type' => 'nullable|in:individual,commercial,government', 'tax_number' => ['nullable', 'regex:/^3\d{13}3$/'],
                    'email' => 'nullable|email', 'credit_limit' => 'nullable|numeric|min:0', 'opening_balance' => 'nullable|numeric'],
                'defaults' => ['customer_type' => 'commercial', 'status' => 'active'],
                'key' => 'code', 'number' => 'customer',
            ],
            'suppliers' => [
                'label' => 'الموردون', 'model' => Supplier::class, 'permission' => ['view' => 'purchases.suppliers.view', 'create' => 'purchases.suppliers.create'],
                'columns' => ['name', 'company_name', 'tax_number', 'phone', 'email', 'address', 'opening_balance'],
                'rules' => ['name' => 'required|string|max:255', 'tax_number' => ['nullable', 'regex:/^3\d{13}3$/'], 'email' => 'nullable|email', 'opening_balance' => 'nullable|numeric'],
                'defaults' => ['status' => 'active'],
                'key' => null, 'number' => null,
            ],
            'products' => [
                'label' => 'الأصناف', 'model' => Product::class, 'permission' => ['view' => 'inventory.products.view', 'create' => 'inventory.products.create'],
                'columns' => ['sku', 'barcode', 'name_ar', 'name_en', 'type', 'selling_price', 'purchase_price', 'tax_rate', 'vat_category', 'vat_exemption_code', 'reorder_level', 'opening_quantity', 'opening_cost', 'warehouse_code'],
                'rules' => ['name_ar' => 'required|string|max:255', 'type' => 'nullable|in:product,service', 'selling_price' => 'required|numeric|min:0',
                    'purchase_price' => 'nullable|numeric|min:0', 'tax_rate' => 'nullable|numeric|min:0|max:100', 'reorder_level' => 'nullable|numeric|min:0',
                    'vat_category' => 'nullable|in:S,Z,E,O', 'vat_exemption_code' => ['nullable', Rule::in(collect(config('zatca.exemption_reasons'))->flatMap(fn ($r) => array_keys($r))->all())],
                    'opening_quantity' => 'nullable|numeric|min:0', 'opening_cost' => 'nullable|numeric|min:0', 'warehouse_code' => 'nullable|string|max:50'],
                'defaults' => ['type' => 'product', 'track_inventory' => true, 'is_active' => true, 'tax_rate' => 15],
                'key' => 'sku', 'number' => 'product',
            ],
            'employees' => [
                'label' => 'الموظفون', 'model' => Employee::class, 'permission' => ['view' => 'hr.employees.view', 'create' => 'hr.employees.create'], 'export_only' => true,
                'columns' => ['employee_number', 'name', 'nationality', 'national_id', 'job_title', 'department', 'hire_date', 'status'],
            ],
            'accounts' => [
                'label' => 'دليل الحسابات', 'model' => Account::class, 'permission' => ['view' => 'accounting.accounts.view', 'create' => 'accounting.accounts.create'], 'export_only' => true,
                'columns' => ['code', 'name_ar', 'name_en', 'type', 'nature', 'is_parent', 'balance'],
            ],
            'opening_balances' => [
                'label' => 'ميزان المراجعة الافتتاحي', 'permission' => ['view' => 'accounting.journals.create', 'create' => 'accounting.journals.create'], 'import_only' => true,
                'columns' => ['account_code', 'debit', 'credit', 'description'],
                'rules' => ['account_code' => 'required|string|max:50', 'debit' => 'nullable|numeric|min:0', 'credit' => 'nullable|numeric|min:0', 'description' => 'nullable|string|max:255'],
            ],
        ];
    }

    protected function definition(string $type, string $action): array
    {
        $definition = $this->definitions()[$type] ?? abort(404);
        abort_unless(auth()->user()->can($definition['permission'][$action]), 403);

        return $definition;
    }

    public function index(Request $request)
    {
        return Inertia::render('Settings/DataTransfer', [
            'types' => collect($this->definitions())->map(fn ($d, $key) => [
                'key' => $key, 'label' => $d['label'], 'columns' => $d['columns'], 'importable' => empty($d['export_only']), 'opening' => $key === 'opening_balances',
                'can_export' => empty($d['import_only']) && $request->user()->can($d['permission']['view']), 'can_import' => empty($d['export_only']) && $request->user()->can($d['permission']['create']),
            ])->values(),
            'result' => session('import_result'),
        ]);
    }

    public function export(string $type): StreamedResponse
    {
        $definition = $this->definition($type, 'view');
        abort_if(! empty($definition['import_only']), 404);
        $columns = array_values(array_diff($definition['columns'], self::OPENING_COLUMNS));

        return response()->streamDownload(function () use ($definition, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);
            $definition['model']::query()->select(array_merge(['id'], $columns))->orderBy('id')->lazy(2000)->each(function ($row) use ($out, $columns) {
                fputcsv($out, array_map(function ($c) use ($row) {
                    $v = $row->getRawOriginal($c);

                    return is_string($v) && preg_match('/^[=+\-@\t\r|]/', $v) ? "'".$v : $v;
                }, $columns));
            });
            fclose($out);
        }, "{$type}-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function template(string $type): StreamedResponse
    {
        $definition = $this->definition($type, 'create');

        return response()->streamDownload(function () use ($definition) {
            echo "\xEF\xBB\xBF".implode(',', $definition['columns'])."\n";
        }, "{$type}-template.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Import rows; existing records (matched on the key column) are updated.
     * All rows are validated first: nothing is written unless every row is valid.
     * Opening balances (party balance, opening stock) are posted for new
     * records only, dated opening_date, so re-importing a file to update
     * contact details never books a balance twice.
     */
    public function import(Request $request, string $type)
    {
        $definition = $this->definition($type, 'create');
        abort_if(! empty($definition['export_only']), 404);
        $request->validate([
            'file' => 'required|file|max:10240|mimes:csv,txt,xlsx,xls',
            'opening_date' => 'nullable|date|before_or_equal:today',
        ]);
        $openingDate = Carbon::parse($request->input('opening_date') ?: today());

        $sheet = Excel::toArray(new class {}, $request->file('file'))[0] ?? [];
        $header = array_map(fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), array_shift($sheet) ?? []);
        $rows = array_values(array_filter($sheet, fn ($r) => array_filter($r, fn ($v) => $v !== null && $v !== '')));

        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'الملف لا يحتوي بيانات.']);
        }
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            throw ValidationException::withMessages(['file' => 'الحد الأقصى '.self::MAX_IMPORT_ROWS.' صف في الملف الواحد.']);
        }
        $required = array_keys(array_filter($definition['rules'], fn ($r) => str_contains(is_array($r) ? implode('|', $r) : $r, 'required')));
        if ($missing = array_diff($required, $header)) {
            throw ValidationException::withMessages(['file' => 'أعمدة إلزامية ناقصة: '.implode(', ', $missing).'. حمّل القالب للاطلاع على الأعمدة.']);
        }

        $warehouses = $type === 'products' ? Warehouse::pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtolower((string) $code) => $id]) : collect();
        $records = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            $data = [];
            foreach ($definition['columns'] as $column) {
                $index = array_search($column, $header, true);
                if ($index !== false) {
                    $value = $row[$index] ?? null;
                    // Spreadsheets hand codes and SKUs back as numbers; validate them as text.
                    $data[$column] = is_string($value) ? trim(ltrim($value, "'")) : (is_int($value) || is_float($value) ? (string) $value : $value);
                }
            }
            $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
            $validator = Validator::make($data, $definition['rules']);
            if ($validator->fails()) {
                $errors[] = 'سطر '.($i + 2).': '.implode('، ', $validator->errors()->all());

                continue;
            }
            if ($type === 'products' && ($message = $this->checkOpeningStock($data, $warehouses))) {
                $errors[] = 'سطر '.($i + 2).': '.$message;

                continue;
            }
            $records[$i + 2] = $data;
        }

        if ($errors) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 20)]);
        }

        if ($type === 'opening_balances') {
            $entry = $this->importTrialBalance($records, $openingDate);

            return back()->with('success', "تم ترحيل القيد الافتتاحي {$entry->entry_number} بإجمالي ".number_format((float) $entry->total_debit, 2));
        }

        [$created, $updated, $skipped] = DB::transaction(function () use ($type, $definition, $records, $openingDate) {
            $created = $updated = $skipped = 0;
            foreach ($records as $data) {
                $opening = array_intersect_key($data, array_flip(self::OPENING_COLUMNS));
                $data = array_diff_key($data, $opening);
                $model = $definition['key'] && ! empty($data[$definition['key']])
                    ? $definition['model']::where($definition['key'], $data[$definition['key']])->first()
                    : null;
                if ($model) {
                    $model->update($data);
                    $updated++;
                    $skipped += (int) (array_filter($opening, fn ($v, $k) => $k !== 'warehouse_code' && (float) $v != 0, ARRAY_FILTER_USE_BOTH) !== []);
                } else {
                    if ($definition['number'] && empty($data[$definition['key']])) {
                        $data[$definition['key']] = $this->nextNumber($definition['number']);
                    }
                    if (in_array($type, ['customers', 'suppliers'], true)) {
                        $data['opening_balance'] = $data['current_balance'] = round((float) ($opening['opening_balance'] ?? 0), 2);
                    }
                    $model = $definition['model']::create($data + $definition['defaults']);
                    $this->postOpening($type, $model, $opening, $openingDate);
                    $created++;
                }
            }

            return [$created, $updated, $skipped];
        });

        $message = "تم الاستيراد: {$created} جديد، {$updated} محدّث";
        if ($skipped) {
            $message .= "؛ تُجوهل الرصيد الافتتاحي لـ {$skipped} سجل موجود مسبقاً (عدّله بقيد أو تسوية مخزنية)";
        }

        return back()->with('success', $message);
    }

    /** Validates the opening stock columns of a product row, resolving warehouse_code to an id. */
    protected function checkOpeningStock(array &$data, $warehouses): ?string
    {
        if ((float) ($data['opening_quantity'] ?? 0) <= 0) {
            return null;
        }
        if (($data['type'] ?? 'product') !== 'product') {
            return 'الخدمات لا يُسجّل لها رصيد مخزون افتتاحي.';
        }
        if (! empty($data['warehouse_code'])) {
            $id = $warehouses->get(mb_strtolower((string) $data['warehouse_code']));
            if (! $id) {
                return "رمز المستودع «{$data['warehouse_code']}» غير موجود.";
            }
            $data['warehouse_code'] = $id;
        } elseif ($warehouses->isEmpty()) {
            return 'أنشئ مستودعاً قبل استيراد أرصدة المخزون.';
        }
        if (! isset($data['opening_cost']) && ! isset($data['purchase_price'])) {
            return 'حدد تكلفة الرصيد الافتتاحي (opening_cost أو purchase_price).';
        }

        return null;
    }

    /** Opening receivable / payable or opening stock of a newly imported record, against opening balance equity. */
    protected function postOpening(string $type, $model, array $opening, Carbon $date): void
    {
        $poster = app(DocumentPoster::class);

        if ($type === 'customers') {
            $poster->openingBalance($model, ['role' => 'receivables', 'side' => 'debit', 'party_type' => 'customer', 'party_id' => $model->id],
                (float) $model->opening_balance, $date, 'رصيد افتتاحي للعميل '.$model->name);
        } elseif ($type === 'suppliers') {
            $poster->openingBalance($model, ['role' => 'payables', 'side' => 'credit', 'party_type' => 'supplier', 'party_id' => $model->id],
                (float) $model->opening_balance, $date, 'رصيد افتتاحي للمورد '.$model->name);
        } elseif ($type === 'products' && (float) ($opening['opening_quantity'] ?? 0) > 0 && $model->isStockItem()) {
            $cost = (float) ($opening['opening_cost'] ?? $model->purchase_price);
            $movement = app(StockService::class)->receive([
                'product_id' => $model->id,
                'warehouse_id' => app(DocumentStock::class)->warehouse(isset($opening['warehouse_code']) ? (int) $opening['warehouse_code'] : null),
                'quantity' => (float) $opening['opening_quantity'],
                'unit_cost' => $cost,
                'type' => 'opening',
                'source' => $model,
                'reference' => $model->sku,
                'date' => $date,
            ]);
            $poster->openingBalance($model, ['role' => 'inventory', 'side' => 'debit'], (float) $movement->total_cost, $date, 'رصيد افتتاحي للصنف '.$model->name_ar);
        }
    }

    /**
     * One balanced opening entry from the trial balance at the go-live date.
     * Control accounts are refused: their balances come from the customer,
     * supplier and product imports, which also fill the sub-ledgers.
     */
    protected function importTrialBalance(array $rows, Carbon $date): JournalEntry
    {
        if (JournalEntry::where('source_type', 'opening_trial_balance')->where('status', JournalEntry::STATUS_POSTED)->exists()) {
            throw ValidationException::withMessages(['file' => 'سبق ترحيل ميزان افتتاحي. اعكس القيد السابق من دفتر القيود قبل استيراد ميزان جديد.']);
        }

        $map = app(AccountMap::class);
        $control = collect(self::CONTROL_ROLES)->mapWithKeys(fn ($source, $role) => [(int) $map->find($role) => $source])->except(0);
        $accounts = Account::whereIn('code', array_map(fn ($r) => (string) $r['account_code'], $rows))->get()->keyBy(fn ($a) => (string) $a->code);

        $errors = [];
        $lines = [];
        foreach ($rows as $line => $row) {
            $code = (string) $row['account_code'];
            $debit = round((float) ($row['debit'] ?? 0), 2);
            $credit = round((float) ($row['credit'] ?? 0), 2);
            $account = $accounts->get($code);

            if (! $account) {
                $errors[] = "سطر {$line}: الحساب {$code} غير موجود في دليل الحسابات.";
            } elseif ($account->is_parent) {
                $errors[] = "سطر {$line}: الحساب {$code} حساب رئيسي؛ استخدم حساباً فرعياً.";
            } elseif ($source = $control->get($account->id)) {
                $errors[] = "سطر {$line}: الحساب {$code} حساب مراقبة؛ يُستورد رصيده من ملف «{$source}».";
            } elseif ($debit > 0 && $credit > 0) {
                $errors[] = "سطر {$line}: ضع المبلغ في المدين أو الدائن فقط.";
            } elseif ($debit > 0 || $credit > 0) {
                $lines[] = ['account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'description' => $row['description'] ?? 'رصيد افتتاحي'];
            }
        }

        $debits = round(array_sum(array_column($lines, 'debit')), 2);
        $credits = round(array_sum(array_column($lines, 'credit')), 2);
        if (! $errors && ! $lines) {
            $errors[] = 'لا توجد مبالغ في الملف.';
        }
        if (! $errors && $debits !== $credits) {
            $errors[] = 'الميزان غير متوازن: المدين '.number_format($debits, 2).' والدائن '.number_format($credits, 2).' (الفرق '.number_format(abs($debits - $credits), 2).').';
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 20)]);
        }

        return app(PostingService::class)->post([
            'date' => $date,
            'reference' => 'OB-'.$date->format('Ymd'),
            'description' => 'ميزان المراجعة الافتتاحي في '.$date->format('Y-m-d'),
            'entry_type' => 'opening',
            'source_type' => 'opening_trial_balance',
            'lines' => $lines,
        ]);
    }
}
