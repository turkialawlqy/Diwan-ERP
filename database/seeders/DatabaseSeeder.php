<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create Roles
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $accountantRole = Role::firstOrCreate(['name' => 'accountant']);
        $salesRole = Role::firstOrCreate(['name' => 'sales']);
        $cashierRole = Role::firstOrCreate(['name' => 'cashier']);
        $employeeRole = Role::firstOrCreate(['name' => 'employee']);

        // 2. Create Default Subscription Plans
        $basicPlan = SubscriptionPlan::create([
            'name_ar' => 'الباقة الأساسية',
            'name_en' => 'Basic Plan',
            'slug' => 'basic',
            'description_ar' => 'مناسبة للمؤسسات الصغيرة والناشئة',
            'description_en' => 'Ideal for small and starting businesses',
            'price_monthly' => 99.00,
            'price_yearly' => 990.00,
            'max_users' => 3,
            'max_branches' => 1,
            'max_invoices_per_month' => 300,
            'features' => ['sales', 'inventory', 'pos', 'reports_basic'],
            'is_active' => true,
            'is_popular' => false,
        ]);

        $proPlan = SubscriptionPlan::create([
            'name_ar' => 'الباقة المتقدمة',
            'name_en' => 'Pro Plan',
            'slug' => 'pro',
            'description_ar' => 'الأكثر طلباً للشركات والمؤسسات المتوسطة',
            'description_en' => 'Most popular for growing companies',
            'price_monthly' => 199.00,
            'price_yearly' => 1990.00,
            'max_users' => 10,
            'max_branches' => 3,
            'max_invoices_per_month' => 2000,
            'features' => ['sales', 'inventory', 'pos', 'purchases', 'finance', 'reports_advanced'],
            'is_active' => true,
            'is_popular' => true,
        ]);

        $enterprisePlan = SubscriptionPlan::create([
            'name_ar' => 'باقة الشركات',
            'name_en' => 'Enterprise Plan',
            'slug' => 'enterprise',
            'description_ar' => 'حل شامل للشركات الكبيرة والمتعددة الفروع',
            'description_en' => 'Comprehensive solution for large enterprises',
            'price_monthly' => 399.00,
            'price_yearly' => 3990.00,
            'max_users' => 50,
            'max_branches' => 10,
            'max_invoices_per_month' => 10000,
            'features' => ['all_features', 'api_access', 'priority_support', 'dedicated_account_manager'],
            'is_active' => true,
            'is_popular' => false,
        ]);

        // 3. Create Super Admin User
        $superAdmin = User::create([
            'name' => 'مدير النظام العام',
            'email' => 'admin@alostaz.io',
            'password' => Hash::make('admin123456'),
            'is_super_admin' => true,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $superAdmin->assignRole($superAdminRole);

        // 4. Create Demo Company (mimicking https://anas.alostaz.io)
        $existingLogos = glob(storage_path('app/public/logos/*.{png,jpg,jpeg,svg,webp}'), GLOB_BRACE);
        $demoLogo = !empty($existingLogos) ? '/storage/logos/' . basename($existingLogos[0]) : null;

        $company = Company::create([
            'name' => 'مؤسسة أنس التجارية',
            'subdomain' => 'anas',
            'email' => 'anas@alostaz.io',
            'phone' => '0555555555',
            'tax_number' => '300000000000003',
            'commercial_registration' => '1010000000',
            'logo' => $demoLogo,
            'status' => 'active',
            'subscription_plan_id' => $proPlan->id,
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'language' => 'ar',
            'is_verified' => true,
        ]);

        // 5. Create Main Branch
        $mainBranch = Branch::create([
            'company_id' => $company->id,
            'name_ar' => 'الفرع الرئيسي',
            'name_en' => 'Main Branch',
            'code' => 'BR-01',
            'phone' => '0555555555',
            'address' => 'الرياض - شارع الملك فهد',
            'city' => 'الرياض',
            'is_main' => true,
            'is_active' => true,
        ]);

        // 6. Create Default Currencies
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

        Currency::create([
            'company_id' => $company->id,
            'name_ar' => 'دولار أمريكي',
            'name_en' => 'US Dollar',
            'code' => 'USD',
            'symbol_ar' => '$',
            'symbol_en' => '$',
            'exchange_rate' => 3.750000,
            'is_default' => false,
        ]);

        // 7. Create Demo Company Admin (Turki Alawlqy from the live system)
        $companyAdmin = User::create([
            'company_id' => $company->id,
            'name' => 'تركي العولقي',
            'email' => 'anas@alostaz.io',
            'password' => Hash::make('password'),
            'phone' => '0555555555',
            'branch_id' => $mainBranch->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $companyAdmin->assignRole($adminRole);

        // 8. Create Company Settings
        $company->setSetting('tax_rate', '15', 'finance', 'integer');
        $company->setSetting('tax_enabled', '1', 'finance', 'boolean');
        $company->setSetting('tax_inclusive', '1', 'finance', 'boolean');
        $company->setSetting('invoice_prefix', 'INV-', 'sales', 'string');
        $company->setSetting('quotation_prefix', 'QUO-', 'sales', 'string');
        $company->setSetting('pos_allow_credit', '1', 'pos', 'boolean');

        // 9. Call Module Demo Seeders
        $this->call([
            AccountingDemoSeeder::class,
            InventoryDemoSeeder::class,
            SalesDemoSeeder::class,
            PurchasesDemoSeeder::class,
            HRAndSettingsDemoSeeder::class,
        ]);
    }
}
