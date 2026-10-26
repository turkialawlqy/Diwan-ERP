<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\AssetDepreciation;
use App\Models\AssetUsage;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\FixedAsset;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\FixedAssetRegister;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,fully_depreciated,disposed',
            'category_id' => 'nullable|integer',
        ]);

        $assets = FixedAsset::with('category:id,name_ar')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name_ar', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")->orWhere('serial_number', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('asset_category_id', $v))
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $totals = FixedAsset::where('status', '!=', 'disposed')
            ->selectRaw('COALESCE(SUM(cost),0) as cost, COALESCE(SUM(accumulated_depreciation + accumulated_impairment),0) as accumulated')
            ->first();

        return Inertia::render('Accounting/FixedAssets/Index', [
            'assets' => $assets,
            'categories' => AssetCategory::with(['assetAccount:id,code,name_ar', 'accumulatedAccount:id,code,name_ar', 'expenseAccount:id,code,name_ar'])->get(),
            'accounts' => Account::where('is_parent', false)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar', 'type']),
            'branches' => Branch::where('is_active', true)->get(['id', 'name_ar']),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
            'totals' => ['cost' => (float) $totals->cost, 'accumulated' => (float) $totals->accumulated, 'book_value' => (float) $totals->cost - (float) $totals->accumulated],
            'filters' => $filters,
        ]);
    }

    public function storeCategory(Request $request, AccountMap $map)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'useful_life_months' => 'required|integer|min:1|max:1200',
            'depreciation_method' => 'required|in:straight_line,declining_balance,units_of_production',
            'declining_rate' => 'nullable|numeric|min:0|max:100',
            'asset_account_id' => 'nullable|tenant_exists:accounts,id',
            'accumulated_account_id' => 'nullable|tenant_exists:accounts,id',
            'expense_account_id' => 'nullable|tenant_exists:accounts,id',
        ]);

        $validated['asset_account_id'] ??= $map->id('fixed_assets');
        $validated['accumulated_account_id'] ??= $map->id('accumulated_depreciation');
        $validated['expense_account_id'] ??= $map->id('depreciation_expense');

        AssetCategory::create($validated);

        return back()->with('success', 'تم إضافة فئة الأصول');
    }

    public function store(Request $request, DepreciationService $depreciation)
    {
        $companyId = app(TenantManager::class)->getTenantId();

        $validated = $request->validate([
            'asset_category_id' => 'required|tenant_exists:asset_categories,id',
            'branch_id' => 'nullable|tenant_exists:branches,id',
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
            'code' => ['required', 'string', 'max:50', Rule::unique('fixed_assets', 'code')->where('company_id', $companyId)],
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'acquisition_date' => 'required|date',
            'depreciation_start_date' => 'nullable|date|after_or_equal:acquisition_date',
            'cost' => 'required|numeric|min:0.01',
            'salvage_value' => 'nullable|numeric|min:0|lt:cost',
            'useful_life_months' => 'nullable|integer|min:1|max:1200',
            'total_units' => 'nullable|numeric|gt:0',
            'unit_label' => 'nullable|string|max:20',
            'paid_from' => 'required|in:cash,bank_transfer,payables,opening',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'supplier_id' => 'nullable|required_if:paid_from,payables|tenant_exists:suppliers,id',
            'acquisition_vat' => 'nullable|numeric|min:0',
            'opening_accumulated' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        $this->guardAmounts($validated);

        $category = AssetCategory::findOrFail($validated['asset_category_id']);
        if ($category->depreciation_method === 'units_of_production' && empty($validated['total_units'])) {
            throw ValidationException::withMessages(['total_units' => 'فئة هذا الأصل تُهلك بوحدات الإنتاج: حدد إجمالي الوحدات المتوقعة طوال عمره (ساعات تشغيل، كيلومترات، وحدات).']);
        }

        DB::transaction(function () use ($validated, $category, $depreciation) {
            $opening = $validated['paid_from'] === 'opening';
            $accumulated = $opening ? round((float) ($validated['opening_accumulated'] ?? 0), 2) : 0.0;
            $asset = FixedAsset::create(array_merge(collect($validated)->except(['paid_from', 'bank_account_id', 'supplier_id'])->all(), [
                // An opening asset depreciates from the go-live month; its earlier depreciation is the opening accumulated amount.
                'depreciation_start_date' => $validated['depreciation_start_date']
                    ?? ($opening ? now()->startOfMonth()->toDateString() : Carbon::parse($validated['acquisition_date'])->startOfMonth()->toDateString()),
                'acquisition_vat' => $opening ? 0 : round((float) ($validated['acquisition_vat'] ?? 0), 2),
                'opening_accumulated' => $accumulated,
                'accumulated_depreciation' => $accumulated,
                'useful_life_months' => $validated['useful_life_months'] ?? $category->useful_life_months,
                'depreciation_method' => $category->depreciation_method,
                'salvage_value' => $validated['salvage_value'] ?? 0,
                'status' => 'active',
            ]));

            $depreciation->acquire($asset, $validated['paid_from'], $validated['bank_account_id'] ?? null, $validated['supplier_id'] ?? null);
        });

        return back()->with('success', 'تم تسجيل الأصل الثابت وترحيل قيد الشراء');
    }

    public function show(FixedAsset $asset): Response
    {
        $asset->load(['category', 'depreciations', 'impairments', 'usages']);
        $service = app(DepreciationService::class);

        return Inertia::render('Accounting/FixedAssets/Show', [
            'asset' => $asset,
            'monthlyAmount' => $service->monthlyAmount($asset),
            'schedule' => $service->schedule($asset),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    public function depreciate(Request $request, DepreciationService $depreciation)
    {
        $validated = $request->validate(['month' => 'required|date_format:Y-m']);

        $result = $depreciation->run(Carbon::createFromFormat('Y-m', $validated['month'])->endOfMonth());

        return back()->with('success', "تم ترحيل إهلاك {$result['assets']} أصل بإجمالي ".number_format($result['amount'], 2));
    }

    public function dispose(Request $request, FixedAsset $asset, DepreciationService $depreciation)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'proceeds' => 'required|numeric|min:0',
            'method' => 'required|in:cash,bank_transfer',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'vat_amount' => 'nullable|numeric|min:0',
        ]);
        $vat = round((float) ($validated['vat_amount'] ?? 0), 2);
        $profile = TaxProfile::forDocument($asset);
        if ($vat > 0 && ! $profile->vatMatches((float) $validated['proceeds'], $vat)) {
            throw ValidationException::withMessages(['vat_amount' => 'ضريبة البيع يجب أن تساوي '.$profile->ratesLabel().' من سعر البيع أو صفراً.']);
        }

        $depreciation->dispose($asset, Carbon::parse($validated['date']), (float) $validated['proceeds'], $validated['bank_account_id'] ?? null, $validated['method'], $vat);

        return back()->with('success', 'تم استبعاد الأصل وترحيل قيد البيع/الاستبعاد');
    }

    /** Register (PP&E note) for a period, by category; CSV with ?export=csv. */
    public function register(Request $request, FixedAssetRegister $register)
    {
        $validated = $request->validate(['start_date' => 'nullable|date', 'end_date' => 'nullable|date|after_or_equal:start_date']);
        $from = Carbon::parse($validated['start_date'] ?? now()->startOfYear()->toDateString());
        $to = Carbon::parse($validated['end_date'] ?? now()->toDateString());
        $report = $register->build($from, $to);

        if ($request->query('export') === 'csv') {
            $headers = ['Category', 'Code', 'Asset', 'Cost (open)', 'Additions', 'Disposals', 'Cost (close)', 'Acc. dep. (open)', 'Acc. dep. acquired',
                'Depreciation', 'Impairment', 'Acc. dep. disposed', 'Acc. dep. (close)', 'Net book value'];

            return response()->streamDownload(function () use ($report, $headers) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $headers);
                foreach ($report['categories'] as $category) {
                    foreach ($category['assets'] as $a) {
                        fputcsv($out, array_merge([$category['name'], $a['code'], $a['name']], array_map(fn ($c) => $a[$c], FixedAssetRegister::COLUMNS)));
                    }
                }
                fclose($out);
            }, 'fixed-asset-register-'.$to->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return Inertia::render('Accounting/FixedAssets/Register', [
            'report' => $report,
            'filters' => ['start_date' => $from->toDateString(), 'end_date' => $to->toDateString()],
        ]);
    }

    /** Units an asset ran or produced in a month (units-of-production depreciation). */
    public function storeUsage(Request $request, FixedAsset $asset)
    {
        abort_unless($asset->depreciation_method === 'units_of_production', 422);
        $validated = $request->validate(['month' => 'required|date_format:Y-m', 'units' => 'required|numeric|min:0']);
        $period = (int) str_replace('-', '', $validated['month']);
        if (AssetDepreciation::where('fixed_asset_id', $asset->id)->where('period', $period)->exists()) {
            throw ValidationException::withMessages(['units' => 'إهلاك هذا الشهر مرحَّل؛ لا يمكن تعديل استخدامه.']);
        }
        AssetUsage::updateOrCreate(['fixed_asset_id' => $asset->id, 'period' => $period], ['units' => $validated['units']]);

        return back()->with('success', 'تم تسجيل استخدام الشهر؛ يُرحَّل إهلاكه مع تشغيل الإهلاك الشهري.');
    }

    public function impair(Request $request, FixedAsset $asset, DepreciationService $depreciation)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'amount' => 'required|numeric|gt:0',
            'reason' => 'required|string|max:500',
        ]);
        $depreciation->impair($asset, Carbon::parse($validated['date']), (float) $validated['amount'], $validated['reason']);

        return back()->with('success', 'تم تسجيل هبوط القيمة؛ يُحتسب الإهلاك القادم على القيمة الدفترية الجديدة.');
    }

    protected function guardAmounts(array $data): void
    {
        $cost = (float) $data['cost'];
        $vat = (float) ($data['acquisition_vat'] ?? 0);
        $profile = TaxProfile::forBranch(isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        if ($vat > 0 && ($data['paid_from'] === 'opening' || ! $profile->vatMatches($cost, $vat))) {
            throw ValidationException::withMessages(['acquisition_vat' => 'ضريبة الشراء يجب أن تساوي '.$profile->ratesLabel().' من التكلفة أو صفراً، ولا تُسجَّل للأصول الافتتاحية.']);
        }
        if ((float) ($data['opening_accumulated'] ?? 0) > 0 && ($data['paid_from'] !== 'opening'
            || (float) $data['opening_accumulated'] > $cost - (float) ($data['salvage_value'] ?? 0))) {
            throw ValidationException::withMessages(['opening_accumulated' => 'مجمع الإهلاك السابق للأصول الافتتاحية فقط، ولا يتجاوز التكلفة ناقص قيمة الخردة.']);
        }
    }
}
