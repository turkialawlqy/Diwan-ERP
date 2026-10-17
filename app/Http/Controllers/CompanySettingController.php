<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Services\Auth\TwoFactor;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class CompanySettingController extends Controller
{
    public function index(Request $request, TenantManager $tenantManager): Response
    {
        $company = $tenantManager->getCompany() ?? $request->user()?->company;

        if (! $company) {
            abort(403, 'لا يمكن تحديد سياق المنشأة. يرجى تسجيل الدخول مجدداً.');
        }

        $settings = CompanySetting::where('company_id', $company?->id)
            ->pluck('value', 'key')
            ->toArray();

        $accounts = Account::where('company_id', $company->id)
            ->where('is_parent', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name_ar', 'name_en', 'type', 'nature']);

        $labels = collect(config('erp_permissions.default_roles'))->map(fn ($r) => $r['ar'] ?? null);
        $roles = Role::where(config('permission.column_names.team_foreign_key'), $company->id)->orderBy('id')->pluck('name')
            ->map(fn ($name) => ['name' => $name, 'label' => $labels[$name] ?? $name])->values();

        return Inertia::render('Settings/Index', [
            'company' => $company,
            'settings' => $settings,
            'accounts' => $accounts,
            'roles' => $roles,
            'twoFactorRoles' => app(TwoFactor::class)->requiredRoles($company),
        ]);
    }

    public function update(Request $request, TenantManager $tenantManager)
    {
        $company = $tenantManager->getCompany() ?? $request->user()?->company;

        if (! $company) {
            abort(403, 'لا يمكن تحديد سياق المنشأة. يرجى تسجيل الدخول مجدداً.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'tax_number' => 'nullable|string|max:50',
            'commercial_registration' => 'nullable|string|max:50',
            'currency' => 'nullable|string|max:10',
            'timezone' => 'nullable|string|max:50',
            'language' => 'nullable|string|in:ar,en',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:3072',

            // Custom settings
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'invoice_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-\/]*$/'],
            'invoice_terms' => 'nullable|string',
            'document_message' => 'nullable|string|max:3000',
            'show_hijri' => 'nullable|boolean',
            'two_factor_required_roles' => 'nullable|array',
            'two_factor_required_roles.*' => 'string|max:100',
            'pos_auto_print' => 'nullable|boolean',

            // ZATCA settings
            'zatca_phase' => 'nullable|string|in:phase1,phase2',
            'zatca_environment' => 'nullable|string|in:sandbox,simulation,production',
            'zatca_otp' => 'nullable|string|max:20',

            // Accounting Settings (توجيه الحسابات المحاسبية)
            'acc_sales_revenue_id' => 'nullable|tenant_exists:accounts,id',
            'acc_purchases_cogs_id' => 'nullable|tenant_exists:accounts,id',
            'acc_vat_output_id' => 'nullable|tenant_exists:accounts,id',
            'acc_vat_input_id' => 'nullable|tenant_exists:accounts,id',
            'acc_receivables_id' => 'nullable|tenant_exists:accounts,id',
            'acc_payables_id' => 'nullable|tenant_exists:accounts,id',
            'acc_default_cash_id' => 'nullable|tenant_exists:accounts,id',
            'acc_default_bank_id' => 'nullable|tenant_exists:accounts,id',
        ]);

        // Enforce that base currency cannot be modified once set for the company
        if (! empty($company->currency)) {
            $currency = $company->currency;
            if ($request->filled('currency') && $request->input('currency') !== $company->currency) {
                throw ValidationException::withMessages([
                    'currency' => 'لا يمكن تغيير العملة الأساسية للمنشأة بعد اختيارها لضمان سلامة القيود المحاسبية والفواتير المسجلة.',
                ]);
            }
        } else {
            $currency = $validated['currency'] ?? 'SAR';
        }

        $companyData = [
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'commercial_registration' => $validated['commercial_registration'] ?? null,
            'currency' => $currency,
            'timezone' => $validated['timezone'] ?? 'Asia/Riyadh',
            'language' => $validated['language'] ?? 'ar',
        ];

        if ($request->hasFile('logo')) {
            if ($company->logo && str_starts_with($company->logo, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $company->logo);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('logo')->store('logos/'.$company->id, 'public');
            $companyData['logo'] = '/storage/'.$path;
        }

        // Update company profile
        $company->update($companyData);

        // Roles that must use two-factor authentication ("*" = everyone).
        $validRoles = Role::where(config('permission.column_names.team_foreign_key'), $company->id)->pluck('name')->push('*')->all();
        app(TwoFactor::class)->setRequiredRoles($company, array_values(array_intersect($validated['two_factor_required_roles'] ?? [], $validRoles)));

        // Save key-value settings
        $settingsToSave = [
            'vat_rate' => 15, // statutory rate; the field is informational
            'invoice_prefix' => $validated['invoice_prefix'] ?? 'INV',
            // The numbering service reads the sales invoice series from this key.
            'sales_invoice_prefix' => ($p = trim((string) ($validated['invoice_prefix'] ?? ''))) !== '' ? (preg_match('/[-\/]$/', $p) ? $p : $p.'-') : '',
            'invoice_terms' => $validated['invoice_terms'] ?? '',
            'document_message' => $validated['document_message'] ?? '',
            'show_hijri' => ($validated['show_hijri'] ?? true) ? '1' : '0',
            'pos_auto_print' => ! empty($validated['pos_auto_print']) ? '1' : '0',
            'zatca_phase' => $validated['zatca_phase'] ?? 'phase1',
            'zatca_environment' => $validated['zatca_environment'] ?? 'sandbox',

            // Accounting Mappings
            'acc_sales_revenue_id' => $validated['acc_sales_revenue_id'] ?? null,
            'acc_purchases_cogs_id' => $validated['acc_purchases_cogs_id'] ?? null,
            'acc_vat_output_id' => $validated['acc_vat_output_id'] ?? null,
            'acc_vat_input_id' => $validated['acc_vat_input_id'] ?? null,
            'acc_receivables_id' => $validated['acc_receivables_id'] ?? null,
            'acc_payables_id' => $validated['acc_payables_id'] ?? null,
            'acc_default_cash_id' => $validated['acc_default_cash_id'] ?? null,
            'acc_default_bank_id' => $validated['acc_default_bank_id'] ?? null,
        ];

        foreach ($settingsToSave as $key => $val) {
            $group = 'general';
            if (str_starts_with($key, 'zatca_')) {
                $group = 'zatca';
            } elseif (str_starts_with($key, 'acc_')) {
                $group = 'accounting';
            }

            CompanySetting::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'key' => $key,
                ],
                [
                    'group' => $group,
                    'value' => (string) ($val ?? ''),
                    'type' => 'string',
                ]
            );
        }

        return redirect()->route('settings.index')
            ->with('success', 'تم حفظ إعدادات المنشأة والفاتورة الإلكترونية بنجاح');
    }

    /**
     * Onboarding moved to the e-invoicing (ZATCA) screen with real CSIDs.
     */
    public function onboardZatca()
    {
        return redirect()->route('zatca.index');
    }

    /**
     * Upload and update company logo.
     */
    public function uploadLogo(Request $request, TenantManager $tenantManager)
    {
        $company = $tenantManager->getCompany() ?? $request->user()?->company;

        if (! $company) {
            abort(403, 'لا يمكن تحديد سياق المنشأة.');
        }

        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:3072',
        ], [
            'logo.required' => 'يرجى اختيار ملف الشعار.',
            'logo.image' => 'يجب أن يكون الملف صورة صالحة.',
            'logo.max' => 'يجب ألا يتجاوز حجم الشعار 3 ميجابايت.',
        ]);

        if ($request->hasFile('logo')) {
            // Delete previous custom logo if exists in storage
            if ($company->logo && str_starts_with($company->logo, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $company->logo);
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('logo')->store('logos/'.$company->id, 'public');
            $logoUrl = '/storage/'.$path;

            $company->update([
                'logo' => $logoUrl,
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'تم رفع وتحديث شعار المنشأة بنجاح',
                    'logo' => $company->logo_url,
                    'logo_url' => $company->logo_url,
                ]);
            }
        }

        return back()->with('success', 'تم رفع وتحديث شعار المنشأة بنجاح');
    }

    /**
     * Stream the current company's logo.
     */
    public function showLogo(TenantManager $tenantManager)
    {
        $path = $tenantManager->getCompany()?->logoPath();

        abort_unless($path, 404);

        return response()->file(Storage::disk('public')->path($path), [
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Delete company logo.
     */
    public function deleteLogo(Request $request, TenantManager $tenantManager)
    {
        $company = $tenantManager->getCompany() ?? $request->user()?->company;

        if (! $company) {
            abort(403, 'لا يمكن تحديد سياق المنشأة.');
        }

        if ($company->logo && str_starts_with($company->logo, '/storage/')) {
            $oldPath = str_replace('/storage/', '', $company->logo);
            Storage::disk('public')->delete($oldPath);
        }

        $company->update([
            'logo' => null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم حذف شعار المنشأة بنجاح',
            ]);
        }

        return back()->with('success', 'تم حذف شعار المنشأة بنجاح');
    }
}
