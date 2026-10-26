<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Currency;
use App\Models\Invoice;
use App\Rules\TaxNumber;
use App\Services\EInvoicing\ProviderRegistry;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use App\Support\CurrencyNames;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branches, each in a country: its tax (rates, registration number,
 * e-invoicing system) and operating currency follow that country.
 */
class BranchController extends Controller
{
    public function index(): Response
    {
        $company = app(TenantManager::class)->getCompany();
        $registry = app(ProviderRegistry::class);

        $branches = Branch::withCount('users')->orderByDesc('is_main')->orderBy('id')->get()->map(function (Branch $branch) use ($company, $registry) {
            $profile = TaxProfile::forBranch($branch, $company);

            return $branch->toArray() + [
                'users_count' => $branch->users_count,
                'profile' => $profile->toArray() + ['einvoicing_label' => $registry->for($profile)->label(), 'seller_tax_number' => $profile->sellerTaxNumber()],
                'locked_country' => Invoice::where('branch_id', $branch->id)->exists(),
            ];
        });

        return Inertia::render('Branches/Index', [
            'branches' => $branches,
            'stats' => ['total_branches' => $branches->count(), 'active_branches' => $branches->where('is_active', true)->count(),
                'countries' => $branches->pluck('profile.country')->unique()->count()],
            'countries' => collect(config('countries'))->map(fn ($c, $code) => [
                'code' => $code, 'name' => $c['name'], 'currency' => $c['currency'], 'currency_name' => $c['currency_name'], 'has_tax' => $c['tax']['has'],
                'tax_name' => $c['tax']['name'], 'rates' => $c['tax']['rates'], 'tax_number_label' => $c['tax_number']['label'], 'tax_number_example' => $c['tax_number']['example'],
                'einvoicing' => $registry->for(new TaxProfile($company, new Branch(['country_code' => $code])))->label(),
            ])->values(),
            'companyCountry' => $company?->country_code ?: 'SA',
            // Rate changes recorded per country (with their effective dates).
            'rateHistory' => $branches->pluck('profile.country')->unique()->mapWithKeys(fn ($code) => [$code => $company?->setting('tax_rates.'.$code) ?? []]),
            'baseCurrency' => $company?->currency ?: 'SAR',
        ]);
    }

    public function store(Request $request)
    {
        $branch = Branch::create($this->validated($request) + ['is_active' => true]);
        $this->ensureCurrency($branch);

        return redirect()->route('branches.index')->with('success', 'تم إضافة الفرع بنجاح');
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $this->validated($request, $branch);
        // Issued documents follow the rules of the country they were issued in.
        if (($data['country_code'] ?? null) !== ($branch->country_code ?: null) && Invoice::where('branch_id', $branch->id)->exists()) {
            throw ValidationException::withMessages(['country_code' => 'لا يمكن تغيير دولة فرع صدرت منه فواتير؛ أنشئ فرعاً جديداً للدولة الأخرى.']);
        }
        $branch->update($data);
        $this->ensureCurrency($branch);

        return redirect()->route('branches.index')->with('success', 'تم حفظ بيانات الفرع');
    }

    /**
     * A change of a country's tax rates from a date (e.g. a new standard
     * rate): documents dated before it keep the old rates.
     */
    public function taxRates(Request $request)
    {
        $data = $request->validate([
            'country' => ['required', Rule::in(array_keys(config('countries')))],
            'standard' => 'required|numeric|min:0|max:100',
            'rates' => 'nullable|string|max:100',
            'from' => 'required|date',
        ], [], ['standard' => 'النسبة الأساسية', 'from' => 'تاريخ السريان']);
        $reduced = collect(preg_split('/[\s,،]+/u', (string) ($data['rates'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($r) => is_numeric($r) && $r >= 0 && $r <= 100 ? round((float) $r, 2) : throw ValidationException::withMessages(['rates' => "النسبة «{$r}» غير صحيحة."]))
            ->reject(fn ($r) => $r == $data['standard'])->unique()->values()->all();

        $company = app(TenantManager::class)->getCompany();
        $key = 'tax_rates.'.$data['country'];
        $history = collect($company->setting($key) ?? [])->reject(fn ($r) => ($r['from'] ?? null) === $data['from'])
            ->push(['from' => $data['from'], 'standard' => round((float) $data['standard'], 2), 'rates' => $reduced])->sortBy('from')->values()->all();
        $company->setSetting($key, json_encode($history), 'tax', 'json');

        return back()->with('success', 'تم حفظ نسب الضريبة لـ'.config("countries.{$data['country']}.name").' ابتداءً من '.$data['from']);
    }

    public function destroy(Branch $branch)
    {
        if ($branch->is_main) {
            return back()->withErrors(['branch' => 'لا يمكن حذف الفرع الرئيسي للمنشأة.']);
        }

        $branch->delete();

        return redirect()->route('branches.index')
            ->with('success', 'تم حذف الفرع بنجاح');
    }

    protected function validated(Request $request, ?Branch $branch = null): array
    {
        $country = strtoupper((string) $request->input('country_code', ''));
        $data = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'code' => 'required|string|max:50',
            'phone' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
            'is_main' => 'boolean',
            'country_code' => ['nullable', Rule::in(array_keys(config('countries')))],
            'currency' => 'nullable|string|size:3',
            'tax_number' => ['nullable', 'string', 'max:30', new TaxNumber($country ?: null)],
            'tax_registered' => 'boolean',
            'commercial_registration' => 'nullable|string|max:30',
        ], [], ['country_code' => 'الدولة', 'tax_number' => 'الرقم الضريبي']);

        $company = app(TenantManager::class)->getCompany();
        // The company's own country is stored as "null" (follows the company); the time zone comes from the country.
        $data['country_code'] = $country && $country !== ($company?->country_code ?: 'SA') ? $country : null;
        $data['currency'] = isset($data['currency']) ? strtoupper($data['currency']) : null;
        $data['timezone'] = $data['country_code'] ? config("countries.{$data['country_code']}.timezone") : null;

        return $data;
    }

    /** A branch currency other than the base needs exchange rates: register it among the company's currencies. */
    protected function ensureCurrency(Branch $branch): void
    {
        $code = TaxProfile::forBranch($branch)->currency();
        $company = app(TenantManager::class)->getCompany();
        if (! $company || $code === strtoupper((string) $company->currency) || Currency::where('code', $code)->exists()) {
            return;
        }
        [$name, , $symbol] = CurrencyNames::for($code);
        // No rate yet: documents in it are refused until the user enters the exchange rate (Settings → Currencies).
        Currency::create(['code' => $code, 'name_ar' => $name, 'name_en' => $code, 'symbol_ar' => $symbol, 'symbol_en' => $code, 'exchange_rate' => 0, 'is_default' => false]);
    }
}
