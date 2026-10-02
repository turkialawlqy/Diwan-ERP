<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Permissions\PermissionRegistry;
use App\Services\TenantManager;
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
        return Inertia::render('Auth/Register');
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
            'currency' => ['nullable', 'string', Rule::in(['SAR', 'AED', 'USD', 'EUR', 'EGP', 'KWD', 'BHD', 'OMR', 'QAR'])],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'subdomain.regex' => 'النطاق الفرعي يجب أن يحتوي على أحرف إنجليزية وأرقام وعلامة (-) فقط ولا يبدأ أو ينتهي بشرطة.',
            'subdomain.not_in' => 'هذا النطاق الفرعي محجوز من قبل إدارة النظام.',
            'subdomain.unique' => 'هذا النطاق الفرعي مسجل بالفعل، يرجى اختيار نطاق آخر.',
        ]);

        $user = DB::transaction(function () use ($request) {
            $basicPlan = SubscriptionPlan::where('slug', 'basic')->first();

            $currenciesMeta = [
                'SAR' => ['name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'symbol_ar' => 'ر.س', 'symbol_en' => 'SAR'],
                'AED' => ['name_ar' => 'درهم إماراتي', 'name_en' => 'UAE Dirham', 'symbol_ar' => 'د.إ', 'symbol_en' => 'AED'],
                'USD' => ['name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar', 'symbol_ar' => '$', 'symbol_en' => 'USD'],
                'EUR' => ['name_ar' => 'يورو', 'name_en' => 'Euro', 'symbol_ar' => '€', 'symbol_en' => 'EUR'],
                'EGP' => ['name_ar' => 'جنيه مصري', 'name_en' => 'Egyptian Pound', 'symbol_ar' => 'ج.م', 'symbol_en' => 'EGP'],
                'KWD' => ['name_ar' => 'دينار كويتي', 'name_en' => 'Kuwaiti Dinar', 'symbol_ar' => 'د.ك', 'symbol_en' => 'KWD'],
                'BHD' => ['name_ar' => 'دينار بحريني', 'name_en' => 'Bahraini Dinar', 'symbol_ar' => 'د.ب', 'symbol_en' => 'BHD'],
                'OMR' => ['name_ar' => 'ريال عماني', 'name_en' => 'Omani Rial', 'symbol_ar' => 'ر.ع', 'symbol_en' => 'OMR'],
                'QAR' => ['name_ar' => 'ريال قطري', 'name_en' => 'Qatari Rial', 'symbol_ar' => 'ر.ق', 'symbol_en' => 'QAR'],
            ];
            $selectedCurrencyCode = $request->input('currency', 'SAR');
            $currencyInfo = $currenciesMeta[$selectedCurrencyCode] ?? $currenciesMeta['SAR'];

            // 1. Create Tenant Company
            $company = Company::create([
                'name' => $request->company_name,
                'subdomain' => strtolower($request->subdomain),
                'email' => $request->email,
                'phone' => $request->phone,
                'status' => 'trial',
                'trial_ends_at' => now()->addDays(14),
                'subscription_plan_id' => $basicPlan?->id,
                'currency' => $selectedCurrencyCode,
                'timezone' => 'Asia/Riyadh',
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
                'name_ar' => $currencyInfo['name_ar'],
                'name_en' => $currencyInfo['name_en'],
                'code' => $selectedCurrencyCode,
                'symbol_ar' => $currencyInfo['symbol_ar'],
                'symbol_en' => $currencyInfo['symbol_en'],
                'exchange_rate' => 1.000000,
                'is_default' => true,
            ]);

            // 4. Default Settings
            $company->setSetting('tax_rate', '15', 'finance', 'integer');
            $company->setSetting('tax_enabled', '1', 'finance', 'boolean');
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
            ]);
            $user->save();

            // Per-company roles (owner + defaults) and owner assignment.
            $registry = app(PermissionRegistry::class);
            $registry->seedCompanyRoles($company);
            $user->assignRole($registry->ownerRole());

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false))->with('success', 'تم إنشاء منشأتك بنجاح! مرحباً بك في نظام الأستاذ.');
    }
}
