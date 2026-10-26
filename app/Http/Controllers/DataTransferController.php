<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Rules\TaxNumber;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
use App\Services\HR\BankSalaryFormats;
use App\Services\Imports\LineImporter;
use App\Services\Inventory\DocumentStock;
use App\Services\Inventory\StockService;
use App\Services\Sales\QuotationService;
use App\Services\Tax\TaxProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk import (CSV / Excel) and streaming CSV export of master data, and the
 * opening balances of a company moving onto the system (party balances,
 * opening stock and the opening trial balance).
 */
class DataTransferController extends Controller
{
    public const MAX_IMPORT_ROWS = 5000;

    /** Columns read as dates (Excel keeps dates as day numbers). */
    protected const DATE_COLUMNS = ['date', 'valid_until', 'hire_date', 'acquisition_date', 'contract_end', 'birth_date', 'id_expiry'];

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
                'rules' => ['name' => 'required|string|max:255', 'customer_type' => 'nullable|in:individual,commercial,government', 'tax_number' => ['nullable', 'string', 'max:50', new TaxNumber],
                    'email' => 'nullable|email', 'credit_limit' => 'nullable|numeric|min:0', 'opening_balance' => 'nullable|numeric'],
                'defaults' => ['customer_type' => 'commercial', 'status' => 'active'],
                'key' => 'code', 'number' => 'customer',
            ],
            'suppliers' => [
                'label' => 'الموردون', 'model' => Supplier::class, 'permission' => ['view' => 'purchases.suppliers.view', 'create' => 'purchases.suppliers.create'],
                'columns' => ['name', 'company_name', 'tax_number', 'phone', 'email', 'address', 'opening_balance'],
                'rules' => ['name' => 'required|string|max:255', 'tax_number' => ['nullable', 'string', 'max:50', new TaxNumber], 'email' => 'nullable|email', 'opening_balance' => 'nullable|numeric'],
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
                'defaults' => ['type' => 'product', 'track_inventory' => true, 'is_active' => true, 'tax_rate' => TaxProfile::rate()],
                'key' => 'sku', 'number' => 'product',
            ],
            'employees' => [
                'label' => 'الموظفون', 'model' => Employee::class, 'permission' => ['view' => 'hr.employees.view', 'create' => 'hr.employees.create'], 'handler' => 'importEmployees',
                'export_columns' => ['employee_number', 'name', 'nationality', 'national_id', 'job_title', 'department', 'hire_date', 'status'],
                'columns' => ['employee_number', 'name', 'nationality', 'id_type', 'national_id', 'job_title', 'department', 'hire_date', 'contract_type', 'contract_end',
                    'phone', 'email', 'gosi_number', 'basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances', 'iban'],
                'rules' => ['employee_number' => 'nullable|string|max:30', 'name' => 'required|string|max:255', 'nationality' => 'required|string|size:2',
                    'id_type' => 'nullable|in:national_id,iqama,border_number', 'national_id' => 'nullable|string|max:20', 'job_title' => 'required|string|max:255',
                    'department' => 'nullable|string|max:255', 'hire_date' => 'required|date', 'contract_type' => 'nullable|in:limited,unlimited', 'contract_end' => 'nullable|date|after:hire_date',
                    'phone' => 'nullable|string|max:20', 'email' => 'nullable|email', 'gosi_number' => 'nullable|string|max:30',
                    'basic_salary' => 'nullable|numeric|min:0', 'housing_allowance' => 'nullable|numeric|min:0', 'transport_allowance' => 'nullable|numeric|min:0',
                    'other_allowances' => 'nullable|numeric|min:0', 'iban' => 'nullable|string|max:34'],
            ],
            'accounts' => [
                'label' => 'دليل الحسابات', 'model' => Account::class, 'permission' => ['view' => 'accounting.accounts.view', 'create' => 'accounting.accounts.create'], 'handler' => 'importAccounts',
                'export_columns' => ['code', 'name_ar', 'name_en', 'type', 'nature', 'is_parent', 'balance'],
                'columns' => ['code', 'name_ar', 'name_en', 'type', 'nature', 'parent_code', 'cash_flow_category'],
                'rules' => ['code' => 'required|string|max:50', 'name_ar' => 'required|string|max:255', 'name_en' => 'nullable|string|max:255',
                    'type' => 'nullable|in:asset,liability,equity,revenue,expense', 'nature' => 'nullable|in:debit,credit', 'parent_code' => 'nullable|string|max:50',
                    'cash_flow_category' => 'nullable|in:operating,investing,financing'],
            ],
            'fixed_assets' => [
                'label' => 'الأصول الثابتة', 'model' => FixedAsset::class, 'permission' => ['view' => 'accounting.fixed_assets.view', 'create' => 'accounting.fixed_assets.create'], 'handler' => 'importFixedAssets',
                'export_columns' => ['code', 'name_ar', 'acquisition_date', 'cost', 'salvage_value', 'useful_life_months', 'accumulated_depreciation', 'accumulated_impairment', 'status'],
                'columns' => ['code', 'name_ar', 'category', 'acquisition_date', 'cost', 'salvage_value', 'useful_life_months', 'opening_accumulated', 'total_units', 'unit_label', 'serial_number', 'location'],
                'rules' => ['code' => 'required|string|max:50', 'name_ar' => 'required|string|max:255', 'category' => 'required|string|max:255', 'acquisition_date' => 'required|date',
                    'cost' => 'required|numeric|gt:0', 'salvage_value' => 'nullable|numeric|min:0', 'useful_life_months' => 'nullable|integer|min:1|max:1200',
                    'opening_accumulated' => 'nullable|numeric|min:0', 'total_units' => 'nullable|numeric|gt:0', 'unit_label' => 'nullable|string|max:20',
                    'serial_number' => 'nullable|string|max:100', 'location' => 'nullable|string|max:255'],
            ],
            'quotations' => [
                'label' => 'عروض الأسعار', 'permission' => ['view' => 'sales.quotations.create', 'create' => 'sales.quotations.create'], 'import_only' => true,
                'columns' => ['quotation_ref', 'customer_code', 'customer_name', 'date', 'valid_until', 'notes', 'code', 'name', 'quantity', 'unit_price', 'discount', 'tax_rate'],
                'rules' => ['quotation_ref' => 'required|string|max:50', 'customer_code' => 'nullable|string|max:50', 'customer_name' => 'nullable|string|max:255',
                    'date' => 'required|date', 'valid_until' => 'nullable|date|after_or_equal:date', 'notes' => 'nullable|string|max:2000',
                    'code' => 'nullable|string|max:100', 'name' => 'nullable|string|max:255', 'quantity' => 'nullable|numeric|gt:0',
                    'unit_price' => 'nullable|numeric|min:0', 'discount' => 'nullable|numeric|min:0', 'tax_rate' => 'nullable|numeric|min:0|max:100'],
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
        $columns = $definition['export_columns'] ?? array_values(array_diff($definition['columns'], self::OPENING_COLUMNS));

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
        $required = array_keys(array_filter($definition['rules'], fn ($r) => in_array('required', is_array($r) ? $r : explode('|', $r), true)));
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
                    // Excel stores dates as day numbers.
                    if (in_array($column, self::DATE_COLUMNS, true) && is_numeric($value) && (float) $value > 1000) {
                        $value = ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
                    }
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
            // Inertia shares the first message per key: send the row errors as one, a line each.
            throw ValidationException::withMessages(['file' => implode("\n", array_slice($errors, 0, 20))]);
        }

        if (! empty($definition['handler'])) {
            return back()->with('success', $this->{$definition['handler']}($records, $request, $openingDate));
        }
        if ($type === 'quotations') {
            $quotations = $this->importQuotations($records);

            return back()->with('success', 'تم إنشاء '.count($quotations).' عرض سعر كمسودة: '.implode('، ', array_map(fn ($q) => $q->quotation_number, $quotations)));
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
     * Employees: new ones are created, an existing employee number updates
     * the profile only (pay changes go through the employee file with an
     * effective date). Pay and IBAN are read only for users with salary
     * access. IDs are checked like the employee form.
     */
    protected function importEmployees(array $records, Request $request): string
    {
        $canSalaries = $request->user()->can('hr.salaries.view');
        $pay = ['basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances', 'iban'];
        $existing = Employee::query()->get(['id', 'employee_number', 'national_id']);
        $departments = Department::query()->pluck('id', 'name');
        $errors = [];
        $seenIds = [];

        foreach ($records as $line => &$row) {
            if (! $canSalaries) {
                $row = array_diff_key($row, array_flip($pay));
            }
            $row['id_type'] ??= strtoupper($row['nationality']) === 'SA' ? 'national_id' : 'iqama';
            $current = ! empty($row['employee_number']) ? $existing->firstWhere('employee_number', (string) $row['employee_number']) : null;
            if (! empty($row['national_id'])) {
                $prefix = ['national_id' => '1', 'iqama' => '2', 'border_number' => '[34]'][$row['id_type']];
                if (! preg_match('/^'.$prefix.'\d{9}$/', (string) $row['national_id'])) {
                    $errors[] = "سطر {$line}: رقم الهوية يجب أن يكون 10 أرقام يبدأ بـ 1 للمواطن و2 للإقامة.";
                } elseif (in_array($row['national_id'], $seenIds, true) || $existing->first(fn ($e) => $e->national_id === (string) $row['national_id'] && $e->id !== $current?->id)) {
                    $errors[] = "سطر {$line}: رقم الهوية {$row['national_id']} مسجل لموظف آخر.";
                }
                $seenIds[] = $row['national_id'];
            }
            if (! empty($row['iban'])) {
                $row['iban'] = strtoupper(str_replace(' ', '', $row['iban']));
                if (! BankSalaryFormats::validIban($row['iban'])) {
                    $errors[] = "سطر {$line}: رقم الآيبان غير صحيح.";
                }
            }
        }
        unset($row);
        if ($errors) {
            throw ValidationException::withMessages(['file' => implode("\n", array_slice($errors, 0, 20))]);
        }

        [$created, $updated] = DB::transaction(function () use ($records, $existing, $departments, $pay) {
            $created = $updated = 0;
            foreach ($records as $row) {
                $row['nationality'] = strtoupper($row['nationality']);
                if (! empty($row['department'])) {
                    $row['department_id'] = $departments[$row['department']] ?? null;
                }
                $current = ! empty($row['employee_number']) ? $existing->firstWhere('employee_number', (string) $row['employee_number']) : null;
                if ($current) {
                    $current->update(array_diff_key($row, array_flip($pay)));
                    $updated++;

                    continue;
                }
                $row['employee_number'] ??= $this->nextNumber('employee');
                $row['total_salary'] = round(collect(['basic_salary', 'housing_allowance', 'transport_allowance', 'other_allowances'])->sum(fn ($f) => (float) ($row[$f] ?? 0)), 2);
                Employee::create($row + ['contract_type' => 'unlimited', 'department' => $row['department'] ?? 'عام', 'status' => 'active',
                    'gosi_registered' => true, 'annual_leave_days' => 21, 'basic_salary' => 0]);
                $created++;
            }

            return [$created, $updated];
        });

        return "تم استيراد الموظفين: {$created} جديد، {$updated} محدّث (البيانات الشخصية فقط؛ تعديل الراتب من ملف الموظف بتاريخ سريان)".($request->user()->can('hr.salaries.view') ? '' : '. لم تُستورد الرواتب لعدم وجود صلاحية الرواتب');
    }

    /**
     * Chart of accounts: a new account goes under its parent (by code, in
     * the system or earlier in the file) and takes the parent's type and
     * nature unless given; an existing code only gets its names updated.
     */
    protected function importAccounts(array $records): string
    {
        $accounts = Account::query()->get()->keyBy(fn ($a) => (string) $a->code);
        $errors = [];
        $planned = [];
        // Parents first: shorter codes before longer ones.
        uasort($records, fn ($a, $b) => strlen((string) $a['code']) <=> strlen((string) $b['code']) ?: strcmp((string) $a['code'], (string) $b['code']));

        foreach ($records as $line => $row) {
            $code = (string) $row['code'];
            if ($accounts->has($code) || isset($planned[$code])) {
                continue;
            }
            $parentCode = isset($row['parent_code']) ? (string) $row['parent_code'] : null;
            $parent = $parentCode ? ($accounts->get($parentCode) ?? $planned[$parentCode] ?? null) : null;
            if ($parentCode && ! $parent) {
                $errors[] = "سطر {$line}: الحساب الأب {$parentCode} غير موجود.";

                continue;
            }
            if ($parent instanceof Account && ! $parent->is_parent && DB::table('journal_entry_lines')->where('account_id', $parent->id)->exists()) {
                $errors[] = "سطر {$line}: الحساب الأب {$parentCode} عليه قيود؛ لا يمكن أن يصبح حساباً رئيسياً.";

                continue;
            }
            $type = $row['type'] ?? ($parent['type'] ?? null);
            if (! $type) {
                $errors[] = "سطر {$line}: حدد نوع الحساب (type) لحساب بلا أب.";

                continue;
            }
            if ($parent && $type !== $parent['type']) {
                $errors[] = "سطر {$line}: نوع الحساب يجب أن يطابق نوع الأب ({$parent['type']}).";

                continue;
            }
            $planned[$code] = ['type' => $type, 'nature' => $row['nature'] ?? ($parent['nature'] ?? (in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit'))];
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => implode("\n", array_slice($errors, 0, 20))]);
        }

        [$created, $updated] = DB::transaction(function () use ($records, $accounts, $planned) {
            $created = $updated = 0;
            foreach ($records as $row) {
                $code = (string) $row['code'];
                if ($account = $accounts->get($code)) {
                    $account->update(array_filter(['name_ar' => $row['name_ar'], 'name_en' => $row['name_en'] ?? null]));
                    $updated++;

                    continue;
                }
                $parent = ! empty($row['parent_code']) ? $accounts->get((string) $row['parent_code']) : null;
                if ($parent && ! $parent->is_parent) {
                    $parent->update(['is_parent' => true]);
                }
                $accounts->put($code, Account::create([
                    'code' => $code, 'name_ar' => $row['name_ar'], 'name_en' => $row['name_en'] ?? null, 'parent_id' => $parent?->id,
                    'type' => $planned[$code]['type'], 'nature' => $planned[$code]['nature'], 'is_parent' => false, 'balance' => 0, 'is_active' => true,
                    'cash_flow_category' => $row['cash_flow_category'] ?? null,
                ]));
                $created++;
            }

            return [$created, $updated];
        });

        return "تم استيراد دليل الحسابات: {$created} حساب جديد، {$updated} محدّث الاسم";
    }

    /**
     * Fixed assets in use when the company starts on the system: posted as
     * opening assets (cost against opening balance equity, with their
     * accumulated depreciation so far), depreciating from the go-live month.
     */
    protected function importFixedAssets(array $records, Request $request, Carbon $openingDate): string
    {
        $categories = AssetCategory::query()->get()->keyBy('name_ar');
        $codes = FixedAsset::query()->pluck('code')->map(fn ($c) => (string) $c)->all();
        $errors = [];
        foreach ($records as $line => $row) {
            $category = $categories->get($row['category']);
            if (! $category) {
                $errors[] = "سطر {$line}: فئة الأصول «{$row['category']}» غير موجودة؛ أنشئها من شاشة الأصول الثابتة.";
            } elseif ($category->depreciation_method === 'units_of_production' && empty($row['total_units'])) {
                $errors[] = "سطر {$line}: الفئة تُهلك بوحدات الإنتاج؛ حدد total_units.";
            }
            if (in_array((string) $row['code'], $codes, true)) {
                $errors[] = "سطر {$line}: رمز الأصل {$row['code']} مستخدم.";
            }
            $codes[] = (string) $row['code'];
            if ((float) ($row['opening_accumulated'] ?? 0) > (float) $row['cost'] - (float) ($row['salvage_value'] ?? 0)) {
                $errors[] = "سطر {$line}: المجمع السابق يتجاوز التكلفة ناقص القيمة المتبقية.";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => implode("\n", array_slice($errors, 0, 20))]);
        }

        $depreciation = app(DepreciationService::class);
        DB::transaction(function () use ($records, $categories, $openingDate, $depreciation) {
            foreach ($records as $row) {
                $category = $categories->get($row['category']);
                $accumulated = round((float) ($row['opening_accumulated'] ?? 0), 2);
                $asset = FixedAsset::create([
                    'asset_category_id' => $category->id, 'code' => (string) $row['code'], 'name_ar' => $row['name_ar'],
                    'serial_number' => $row['serial_number'] ?? null, 'location' => $row['location'] ?? null,
                    'acquisition_date' => $row['acquisition_date'], 'depreciation_start_date' => $openingDate->copy()->startOfMonth()->toDateString(),
                    'cost' => (float) $row['cost'], 'salvage_value' => (float) ($row['salvage_value'] ?? 0),
                    'useful_life_months' => (int) ($row['useful_life_months'] ?? $category->useful_life_months),
                    'depreciation_method' => $category->depreciation_method, 'total_units' => $row['total_units'] ?? null, 'unit_label' => $row['unit_label'] ?? null,
                    'opening_accumulated' => $accumulated, 'accumulated_depreciation' => $accumulated, 'acquisition_vat' => 0, 'status' => 'active',
                ]);
                $depreciation->acquire($asset, 'opening');
            }
        });

        return 'تم استيراد '.count($records).' أصل ثابت كأصول افتتاحية بقيودها';
    }

    /**
     * Quotations from one sheet: a row per line, the rows sharing a
     * quotation_ref form one quotation (the reference of the previous
     * system is kept in its notes). All are created as drafts, or none.
     *
     * @return array<int, Quotation>
     */
    protected function importQuotations(array $records): array
    {
        $groups = collect($records)->groupBy(fn ($r) => (string) $r['quotation_ref'], true);
        $customers = Customer::query()->get(['id', 'code', 'name', 'price_list_id']);
        $importer = app(LineImporter::class);
        $errors = [];
        $documents = [];

        foreach ($groups as $ref => $rows) {
            $first = $rows->first();
            $customer = ! empty($first['customer_code'])
                ? $customers->first(fn ($c) => (string) $c->code === (string) $first['customer_code'])
                : $customers->first(fn ($c) => $c->name === ($first['customer_name'] ?? null));
            if (! $customer) {
                $errors[] = "العرض {$ref}: العميل ".($first['customer_code'] ?? $first['customer_name'] ?? '(فارغ)').' غير موجود.';

                continue;
            }
            $result = $importer->items(['code', 'name'], $rows->all(), 'sales', $customer);
            foreach ($result['errors'] as $error) {
                $errors[] = "العرض {$ref}: {$error}";
            }
            if (! $result['lines']) {
                continue;
            }
            $documents[] = [
                'customer_id' => $customer->id,
                'quotation_date' => $first['date'],
                'valid_until' => $first['valid_until'] ?? null,
                'notes' => trim(($first['notes'] ?? '')."\nمرجع العرض السابق: {$ref}"),
                'items' => $result['lines'],
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages(['file' => implode("\n", array_slice($errors, 0, 20))]);
        }

        return DB::transaction(fn () => array_map(fn ($data) => app(QuotationService::class)->create($data), $documents));
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
            // Inertia shares the first message per key: send the row errors as one, a line each.
            throw ValidationException::withMessages(['file' => implode("\n", array_slice($errors, 0, 20))]);
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
