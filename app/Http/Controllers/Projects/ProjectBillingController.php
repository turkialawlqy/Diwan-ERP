<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\ProgressBilling;
use App\Models\Project;
use App\Services\Projects\ProjectService;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectBillingController extends Controller
{
    public function __construct(protected ProjectService $projects) {}

    public function store(Request $request, Project $project)
    {
        $validated = $request->validate([
            'billing_date' => 'required|date',
            'period_from' => 'nullable|date',
            'period_to' => 'nullable|date|after_or_equal:period_from',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:5000',
            'lines.*.boq_item_id' => 'required|integer',
            'lines.*.current_quantity' => 'required|numeric|min:0',
        ]);

        $billing = $this->projects->draftBilling($project, $validated, $validated['lines']);

        return redirect()->route('projects.billings.show', [$project->id, $billing->id])->with('success', "تم إعداد المستخلص {$billing->number} كمسودة");
    }

    public function show(Project $project, ProgressBilling $billing)
    {
        abort_unless((int) $billing->project_id === (int) $project->id, 404);

        return Inertia::render('Projects/Billing', [
            'project' => $project->load('customer:id,name,tax_number'),
            'billing' => $billing->load(['items.boqItem', 'invoice:id,invoice_number,zatca_status,zatca_qr,total']),
            'company' => app(TenantManager::class)->getCompany()?->only(['name', 'tax_number']),
        ]);
    }

    public function approve(Project $project, ProgressBilling $billing)
    {
        abort_unless((int) $billing->project_id === (int) $project->id, 404);
        $invoice = $this->projects->approveBilling($billing);

        return back()->with('success', "تم اعتماد المستخلص وإصدار الفاتورة الضريبية {$invoice->invoice_number}");
    }

    public function destroy(Project $project, ProgressBilling $billing)
    {
        abort_unless((int) $billing->project_id === (int) $project->id && $billing->status === 'draft', 404);
        $billing->items()->delete();
        $billing->delete();

        return redirect()->route('projects.show', $project->id)->with('success', 'تم حذف مسودة المستخلص');
    }
}
