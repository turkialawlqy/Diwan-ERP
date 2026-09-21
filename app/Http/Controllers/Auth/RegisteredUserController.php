<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\SubscriptionPlan;
use App\Models\User;
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
use Spatie\Permission\Models\Role;

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
        $reservedSubdomains = ['admin', 'api', 'www', 'mail', 'app', 'dashboard', 'localhost', '127'];

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
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'subdomain.regex' => 'النطاق الفرعي يجب أن يحتوي على أحرف إنجليزية وأرقام وعلامة (-) فقط ولا يبدأ أو ينتهي بشرطة.',
            'subdomain.not_in' => 'هذا النطاق الفرعي محجوز من قبل إدارة النظام.',
            'subdomain.unique' => 'هذا النطاق الفرعي مسجل بالفعل، يرجى اختيار نطاق آخر.',
        ]);

        $user = DB::transaction(function () use ($request) {
            $basicPlan = SubscriptionPlan::where('slug', 'basic')->first();

            // 1. Create Tenant Company
            $company = Company::create([
                'name' => $request->company_name,
                'subdomain' => strtolower($request->subdomain),
                'email' => $request->email,
                'phone' => $request->phone,
                'status' => 'trial',
                'trial_ends_at' => now()->addDays(14),
                'subscription_plan_id' => $basicPlan?->id,
                'currency' => 'SAR',
                'timezone' => 'Asia/Riyadh',
                'language' => 'ar',
                'is_verified' => false,
            ]);

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

            // 3. Create Default Currency (SAR)
            Currency::create([
                'company_id' => $company->id,
                'name_ar' => 'ريال سعودي',
                'name_en' => 'Saudi Riyal',
                'code' => 'SAR',
                'symbol_ar' => 'ر.س',
                'symbol_en' => 'SAR',
                'exchange_rate' => 1.000000,
                'is_default' => true,
            ]);

            // 4. Default Settings
            $company->setSetting('tax_rate', '15', 'finance', 'integer');
            $company->setSetting('tax_enabled', '1', 'finance', 'boolean');
            $company->setSetting('invoice_prefix', 'INV-', 'sales', 'string');

            // 5. Create Administrator User
            $user = User::create([
                'company_id' => $company->id,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'branch_id' => $branch->id,
                'password' => Hash::make($request->password),
                'status' => 'active',
            ]);

            $adminRole = Role::firstOrCreate(['name' => 'admin']);
            $user->assignRole($adminRole);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false))->with('success', 'تم إنشاء منشأتك بنجاح! مرحباً بك في نظام الأستاذ.');
    }
}
