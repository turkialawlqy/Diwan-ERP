<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk import (CSV / Excel) and streaming CSV export of master data.
 */
class DataTransferController extends Controller
{
    public const MAX_IMPORT_ROWS = 5000;

    protected function definitions(): array
    {
        return [
            'customers' => [
                'label' => 'العملاء', 'model' => Customer::class, 'permission' => ['view' => 'sales.clients.view', 'create' => 'sales.clients.create'],
                'columns' => ['code', 'name', 'company_name', 'customer_type', 'tax_number', 'commercial_registration', 'phone', 'email', 'street_name', 'building_number', 'district', 'city', 'postal_code', 'credit_limit'],
                'rules' => ['name' => 'required|string|max:255', 'customer_type' => 'nullable|in:individual,commercial,government', 'tax_number' => ['nullable', 'regex:/^3\d{13}3$/'],
                    'email' => 'nullable|email', 'credit_limit' => 'nullable|numeric|min:0'],
                'defaults' => ['customer_type' => 'commercial', 'status' => 'active'],
                'key' => 'code', 'number' => 'customer',
            ],
            'suppliers' => [
                'label' => 'الموردون', 'model' => Supplier::class, 'permission' => ['view' => 'purchases.suppliers.view', 'create' => 'purchases.suppliers.create'],
                'columns' => ['name', 'company_name', 'tax_number', 'phone', 'email', 'address'],
                'rules' => ['name' => 'required|string|max:255', 'tax_number' => ['nullable', 'regex:/^3\d{13}3$/'], 'email' => 'nullable|email'],
                'defaults' => ['status' => 'active'],
                'key' => null, 'number' => null,
            ],
            'products' => [
                'label' => 'الأصناف', 'model' => Product::class, 'permission' => ['view' => 'inventory.products.view', 'create' => 'inventory.products.create'],
                'columns' => ['sku', 'barcode', 'name_ar', 'name_en', 'type', 'selling_price', 'purchase_price', 'tax_rate', 'vat_category', 'vat_exemption_code', 'reorder_level'],
                'rules' => ['name_ar' => 'required|string|max:255', 'type' => 'nullable|in:product,service', 'selling_price' => 'required|numeric|min:0',
                    'purchase_price' => 'nullable|numeric|min:0', 'tax_rate' => 'nullable|numeric|min:0|max:100', 'reorder_level' => 'nullable|numeric|min:0',
                    'vat_category' => 'nullable|in:S,Z,E,O', 'vat_exemption_code' => ['nullable', Rule::in(collect(config('zatca.exemption_reasons'))->flatMap(fn ($r) => array_keys($r))->all())]],
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
                'key' => $key, 'label' => $d['label'], 'columns' => $d['columns'], 'importable' => empty($d['export_only']),
                'can_export' => $request->user()->can($d['permission']['view']), 'can_import' => empty($d['export_only']) && $request->user()->can($d['permission']['create']),
            ])->values(),
            'result' => session('import_result'),
        ]);
    }

    public function export(string $type): StreamedResponse
    {
        $definition = $this->definition($type, 'view');
        $columns = $definition['columns'];

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
     */
    public function import(Request $request, string $type)
    {
        $definition = $this->definition($type, 'create');
        abort_if(! empty($definition['export_only']), 404);
        $request->validate(['file' => 'required|file|max:10240|mimes:csv,txt,xlsx,xls']);

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

        $records = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            $data = [];
            foreach ($definition['columns'] as $column) {
                $index = array_search($column, $header, true);
                if ($index !== false) {
                    $value = $row[$index] ?? null;
                    $data[$column] = is_string($value) ? trim(ltrim($value, "'")) : $value;
                }
            }
            $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
            $validator = Validator::make($data, $definition['rules']);
            if ($validator->fails()) {
                $errors[] = 'سطر '.($i + 2).': '.implode('، ', $validator->errors()->all());

                continue;
            }
            $records[] = $data;
        }

        if ($errors) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 20)]);
        }

        [$created, $updated] = DB::transaction(function () use ($definition, $records) {
            $created = $updated = 0;
            foreach ($records as $data) {
                $model = $definition['key'] && ! empty($data[$definition['key']])
                    ? $definition['model']::where($definition['key'], $data[$definition['key']])->first()
                    : null;
                if ($model) {
                    $model->update($data);
                    $updated++;
                } else {
                    if ($definition['number'] && empty($data[$definition['key']])) {
                        $data[$definition['key']] = $this->nextNumber($definition['number']);
                    }
                    $definition['model']::create($data + $definition['defaults']);
                    $created++;
                }
            }

            return [$created, $updated];
        });

        return back()->with('success', "تم الاستيراد: {$created} جديد، {$updated} محدّث");
    }
}
