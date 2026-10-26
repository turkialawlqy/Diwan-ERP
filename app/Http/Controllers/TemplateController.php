<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PrintTemplate;
use App\Services\Documents\FreeformLayout;
use App\Services\Documents\FreeformPresets;
use App\Services\Documents\FreeformTemplates;
use App\Services\Documents\TemplateData;
use App\Services\Tax\TaxProfile;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    /**
     * List all print templates.
     */
    public function index(): Response
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId();

        $templates = PrintTemplate::when($companyId, function ($query) use ($companyId) {
            return $query->where('company_id', $companyId);
        })
            ->latest()
            ->get();

        // Ensure each template has valid layout_data
        foreach ($templates as $t) {
            if (empty($t->layout_data)) {
                $t->layout_data = PrintTemplate::getDefaultLayoutData();
                $t->save();
            }
        }

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
        ]);
    }

    /**
     * Visual Template Designer.
     */
    public function designer(PrintTemplate $template): Response
    {
        if (FreeformTemplates::isFreeform($template)) {
            return app(FreeformTemplateController::class)->designer($template);
        }

        $tenantManager = app(TenantManager::class);
        $company = $tenantManager->getCompany();
        $companyId = $tenantManager->getTenantId();

        if (empty($template->layout_data)) {
            $template->layout_data = PrintTemplate::getDefaultLayoutData();
            $template->save();
        }

        $allTemplates = PrintTemplate::when($companyId, function ($query) use ($companyId) {
            return $query->where('company_id', $companyId);
        })
            ->select('id', 'name', 'template_type', 'is_default')
            ->get();

        // Sample real invoices for preview selector
        $sampleInvoices = Invoice::when($companyId, function ($query) use ($companyId) {
            return $query->where('company_id', $companyId);
        })
            ->select('id', 'invoice_number', 'invoice_date', 'total')
            ->latest()
            ->take(10)
            ->get();

        // Get full first invoice or provide fallback mock
        $activeInvoice = null;
        if ($sampleInvoices->isNotEmpty()) {
            $activeInvoice = Invoice::with(['customer', 'branch', 'items'])->find($sampleInvoices->first()->id);
        }

        if (! $activeInvoice) {
            $activeInvoice = [
                'invoice_number' => 'INV-26-1-000004',
                'reference_number' => 'REF-98421',
                'invoice_date' => now()->format('Y-m-d'),
                'supply_date' => now()->format('Y-m-d'),
                'payment_status' => 'paid',
                'payment_method' => 'bank_transfer',
                'subtotal' => 11927.70,
                'tax_rate' => TaxProfile::rate(),
                'tax_amount' => 1789.16,
                'total' => 13716.86,
                'paid_amount' => 13716.86,
                'remaining_amount' => 0.00,
                'zatca_qr' => 'ARtPbnN5cyBFeGFtcGxlIFFyIENvZGUA',
                'notes' => 'مستخلص جاري رقم 2 - مشروع فلل الياسمين',
                'customer' => [
                    'name' => 'شركة مدن الراقية المحدودة',
                    'tax_number' => '311576763100003',
                    'phone' => '0501234567',
                    'address' => 'حي الكوكية - مبنى 4002 - جدة',
                ],
                'items' => [
                    [
                        'id' => 1,
                        'item_name' => 'عمارة رقم 1151 مستخلص جاري رقم 2',
                        'quantity' => 1,
                        'unit_price' => 11927.70,
                        'tax_amount' => 1789.16,
                        'subtotal' => 11927.70,
                        'total' => 13716.86,
                    ],
                ],
            ];
        }

        return Inertia::render('Templates/Designer', [
            'template' => $template,
            'templates' => $allTemplates,
            'company' => $company,
            'sampleInvoices' => $sampleInvoices,
            'defaultInvoice' => $activeInvoice,
        ]);
    }

    /**
     * Store a new template.
     */
    public function store(Request $request)
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'mode' => 'nullable|in:quick,freeform',
            'template_type' => 'required|string|max:50',
            'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        // Free-form: elements placed anywhere, starting from the kind's layout.
        if (($validated['mode'] ?? 'quick') === 'freeform') {
            $request->validate(['template_type' => 'in:'.implode(',', array_keys(TemplateData::KINDS))], ['template_type.in' => 'اختر نوع المستند.']);
            $template = PrintTemplate::create([
                'company_id' => $companyId,
                'name' => $validated['name'],
                'template_type' => $validated['template_type'],
                'primary_color' => '#000000',
                'layout_data' => ['mode' => 'freeform', 'freeform' => FreeformLayout::sanitize(FreeformPresets::for($validated['template_type']), $companyId)],
                'is_default' => ! PrintTemplate::where('company_id', $companyId)->whereIn('template_type', FreeformTemplates::group($validated['template_type']))->where('is_default', true)->exists()
                    && $validated['template_type'] !== 'invoice',
            ]);

            return redirect()->route('templates.designer', $template->id)->with('success', 'تم إنشاء القالب. اسحب العناصر لأي مكان، وأضف ما تريد من القائمة.');
        }

        $defaultLayout = PrintTemplate::getDefaultLayoutData();
        if (! empty($validated['primary_color'])) {
            $defaultLayout['design']['primary_color'] = $validated['primary_color'];
        }

        $template = PrintTemplate::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'template_type' => $validated['template_type'],
            'primary_color' => $validated['primary_color'] ?? '#1e293b',
            'show_logo' => true,
            'show_qr' => true,
            'show_stamp' => true,
            'show_bank_details' => true,
            'terms_and_conditions' => 'تعتبر هذه الفاتورة ضريبية رسمية وتخضع لأنظمة هيئة الزكاة والضريبة والجمارك.',
            'footer_text' => 'شكراً لتعاملكم معنا',
            'layout_data' => $defaultLayout,
            'is_default' => false,
        ]);

        return redirect()->route('templates.designer', $template->id)
            ->with('success', 'تم إنشاء القالب الجديد بنجاح');
    }

    /**
     * Update the template and its layout structure.
     */
    public function update(Request $request, PrintTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'show_logo' => 'nullable|boolean',
            'show_qr' => 'nullable|boolean',
            'show_stamp' => 'nullable|boolean',
            'show_bank_details' => 'nullable|boolean',
            'terms_and_conditions' => 'nullable|string',
            'footer_text' => 'nullable|string',
            'layout_data' => 'nullable|array',
            'is_default' => 'nullable|boolean',
        ]);

        if (FreeformTemplates::isFreeform($template)) {
            unset($validated['layout_data']);
            if ($request->has('freeform')) {
                $validated['layout_data'] = ['mode' => 'freeform', 'freeform' => FreeformLayout::sanitize((array) $request->input('freeform'), $template->company_id)];
            }
        } elseif (isset($validated['layout_data']) && is_array($validated['layout_data'])) {
            $currentLayout = $template->layout_data ?: PrintTemplate::getDefaultLayoutData();
            $validated['layout_data'] = array_replace_recursive($currentLayout, $validated['layout_data']);
        }

        $template->update($validated);

        return back()->with('success', 'تم حفظ إعدادات وتصميم القالب بنجاح');
    }

    /**
     * Duplicate an existing template.
     */
    public function duplicate(PrintTemplate $template)
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? $template->company_id;

        $newTemplate = $template->replicate();
        $newTemplate->name = 'نسخة من '.$template->name;
        $newTemplate->is_default = false;
        $newTemplate->company_id = $companyId;
        $newTemplate->save();

        return redirect()->route('templates.designer', $newTemplate->id)
            ->with('success', 'تم استنساخ القالب بنجاح');
    }

    /**
     * Set a template as default for its type.
     */
    public function setDefault(PrintTemplate $template)
    {
        $tenantManager = app(TenantManager::class);
        $companyId = $tenantManager->getTenantId() ?? $template->company_id;

        PrintTemplate::where('company_id', $companyId)
            ->whereIn('template_type', FreeformTemplates::group($template->template_type))
            ->update(['is_default' => false]);

        $template->update(['is_default' => true]);

        return back()->with('success', 'تم تعيين القالب كافتراضي بنجاح');
    }

    /**
     * Delete a template.
     */
    public function destroy(PrintTemplate $template)
    {
        if ($template->is_default) {
            return back()->with('error', 'لا يمكن حذف القالب الافتراضي. يرجى تعيين قالب آخر كافتراضي أولاً.');
        }

        $template->delete();

        return redirect()->route('templates.index')
            ->with('success', 'تم حذف القالب بنجاح');
    }

    /**
     * Fetch real invoice data for live preview.
     */
    public function getInvoicePreview(Invoice $invoice)
    {
        $invoice->load(['customer', 'branch', 'items']);

        return response()->json($invoice);
    }
}
