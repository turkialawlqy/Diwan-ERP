<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Accounting\AccountingSetupService;
use App\Services\Permissions\PermissionRegistry;
use App\Services\TenantManager;
use App\Support\CurrencyNames;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            // The country sets the base currency, the tax and the time zone.
            'countries' => collect(config('countries'))->map(fn ($c, $code) => ['code' => $code, 'name' => $c['name'], 'currency' => $c['currency'],
                'currency_name' => $c['currency_name'], 'symbol' => $c['symbol'], 'tax' => $c['tax']['has'] ? $c['tax']['name'].' '.$c['tax']['standard'].'%' : 'بدون ضريبة قيمة مضافة'])->values(),
            'currencies' => self::currencies(),
        ]);
    }

    /** Currencies a company can keep its books in: the Arab currencies plus USD / EUR. */
    public static function currencies(): array
    {
        return collect(config('countries'))->pluck('currency')->merge(['USD', 'EUR'])->unique()
            ->map(fn ($code) => ['code' => $code, 'name' => CurrencyNames::for($code)[0], 'symbol' => CurrencyNames::for($code)[2]])->values()->all();
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $reservedSubdomains = config('tenancy.reserved_subdomains', []);

        $request->validate([
            'company_name' => 'required|string|max:255',
            'subdomain' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/',
                Rule::notIn($reservedSubdomains),
                'unique:companies,subdomain',
            ],
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'phone' => 'required|string|max:20',
            'country_code' => ['nullable', Rule::in(array_keys(config('countries')))],
            'currency' => ['nullable', 'string', Rule::in(array_column(self::currencies(), 'code'))],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'terms' => 'accepted',
        ], [
            'terms.accepted' => 'يجب الموافقة على الشروط والأحكام وسياسة الخصوصية.',
            'subdomain.regex' => 'النطاق الفرعي يجب أن يحتوي على أحرف إنجليزية وأرقام وعلامة (-) فقط ولا يبدأ أو ينتهي بشرطة.',
            'subdomain.not_in' => 'هذا النطاق الفرعي محجوز من قبل إدارة النظام.',
            'subdomain.unique' => 'هذا النطاق الفرعي مسجل بالفعل، يرجى اختيار نطاق آخر.',
        ]);

        $user = DB::transaction(function () use ($request) {
            // New companies try the most popular plan; they choose a plan when subscribing.
            $basicPlan = SubscriptionPlan::where('is_active', true)->where('is_popular', true)->first()
                ?? SubscriptionPlan::where('slug', 'basic')->first();

            $countryCode = $request->input('country_code', 'SA');
            $country = config("countries.{$countryCode}");
            $selectedCurrencyCode = $request->input('currency') ?: $country['currency'];
            [$currencyName, , $currencySymbol] = CurrencyNames::for($selectedCurrencyCode);

            // 1. Create Tenant Company
            $company = Company::create([
                'name' => $request->company_name,
                'subdomain' => strtolower($request->subdomain),
                'email' => $request->email,
                'phone' => $request->phone,
                'status' => 'trial',
                'trial_ends_at' => now()->addDays($basicPlan?->trial_days ?: 14),
                'subscription_plan_id' => $basicPlan?->id,
                'currency' => $selectedCurrencyCode,
                'country_code' => $countryCode,
                'timezone' => $country['timezone'],
                'language' => 'ar',
                'is_verified' => false,
            ]);

            // Activate the new company as the current tenant immediately so that
            // every tenant-scoped record created below (branch, currency, user)
            // is unambiguously attached to it, regardless of any fallback tenant
            // resolution middleware may have applied to this (pre-login) request.
            app(TenantManager::class)->setTenant($company);

            // 2. Create Default Main Branch
            $branch = Branch::create([
                'company_id' => $company->id,
                'name_ar' => 'الفرع الرئيسي',
                'name_en' => 'Main Branch',
                'code' => 'BR-01',
                'phone' => $request->phone,
                'is_main' => true,
                'is_active' => true,
            ]);

            // 3. Create Default Currency Record
            Currency::create([
                'company_id' => $company->id,
                'name_ar' => $currencyName,
                'name_en' => $selectedCurrencyCode,
                'code' => $selectedCurrencyCode,
                'symbol_ar' => $currencySymbol,
                'symbol_en' => $selectedCurrencyCode,
                'exchange_rate' => 1.000000,
                'is_default' => true,
            ]);

            // 4. Default Settings
            $company->setSetting('tax_rate', (string) $country['tax']['standard'], 'finance', 'string');
            $company->setSetting('tax_enabled', $country['tax']['has'] ? '1' : '0', 'finance', 'boolean');
            $company->setSetting('invoice_prefix', 'INV-', 'sales', 'string');

            // 5. Create Administrator User
            $user = (new User)->forceFill([
                'company_id' => $company->id,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'branch_id' => $branch->id,
                'password' => Hash::make($request->password),
                'status' => 'active',
                'terms_accepted_at' => now(), // PDPL: consent to terms and privacy policy
            ]);
            $user->save();

            // Chart of accounts, account mappings and fiscal year.
            app(AccountingSetupService::class)->provision($company);

            // Per-company roles (owner + defaults) and owner assignment.
            $registry = app(PermissionRegistry::class);
            $registry->seedCompanyRoles($company);
            $user->assignRole($registry->ownerRole());

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false))->with('success', 'تم إنشاء منشأتك بنجاح! مرحباً بك في '.config('app.name').'.');
    }
}
