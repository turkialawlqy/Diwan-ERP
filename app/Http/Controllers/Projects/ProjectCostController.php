<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Subcontract;
use App\Services\Projects\ProjectService;
use Illuminate\Http\Request;

/**
 * Materials, direct costs and subcontracts of a project.
 */
class ProjectCostController extends Controller
{
    public function __construct(protected ProjectService $projects) {}

    public function materials(Request $request, Project $project)
    {
        $validated = $request->validate([
            'direction' => 'required|in:issue,return',
            'date' => 'required|date',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'reference' => 'nullable|string|max:50',
            'lines' => 'required|array|min:1|max:500',
            'lines.*.product_id' => 'required|tenant_exists:products,id',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.boq_item_id' => 'nullable|integer',
        ]);

        $value = $this->projects->moveMaterials($project, $validated, $validated['lines'], $validated['direction'] === 'return');

        return back()->with('success', ($validated['direction'] === 'return' ? 'تم إرجاع مواد بقيمة ' : 'تم صرف مواد بقيمة ').number_format($value, 2));
    }

    public function store(Request $request, Project $project)
    {
        $this->projects->recordCost($project, $request->validate([
            'date' => 'required|date',
            'category' => 'required|in:labour,equipment,other',
            'description' => 'required|string|max:500',
            'amount' => 'required|numeric|gt:0',
            'vat_amount' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'paid_from' => 'required|in:cash,bank_transfer,payables,accrued',
            'supplier_id' => 'nullable|required_if:paid_from,payables|tenant_exists:suppliers,id',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'fixed_asset_id' => 'nullable|tenant_exists:fixed_assets,id',
            'boq_item_id' => 'nullable|integer',
            'reference' => 'nullable|string|max:50',
        ]));

        return back()->with('success', 'تم تسجيل التكلفة على المشروع');
    }

    public function storeSubcontract(Request $request, Project $project)
    {
        $subcontract = $this->projects->createSubcontract($project, $request->validate([
            'supplier_id' => 'required|tenant_exists:suppliers,id',
            'scope' => 'required|string|max:1000',
            'contract_value' => 'required|numeric|gt:0',
            'retention_percent' => 'nullable|numeric|min:0|max:100',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]));

        return back()->with('success', "تم تسجيل عقد الباطن {$subcontract->number}");
    }

    public function subcontractAdvance(Request $request, Subcontract $subcontract)
    {
        $this->projects->paySubcontractAdvance($subcontract, $request->validate([
            'date' => 'required|date',
            'amount' => 'required|numeric|gt:0',
            'payment_method' => 'required|in:cash,bank_transfer,cheque',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
        ]));

        return back()->with('success', 'تم صرف الدفعة المقدمة لمقاول الباطن');
    }

    public function certify(Request $request, Subcontract $subcontract)
    {
        $certificate = $this->projects->certify($subcontract, $request->validate([
            'date' => 'required|date',
            'cumulative_value' => 'required|numeric|gt:0',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'supplier_invoice_number' => 'nullable|string|max:50',
        ]));

        return back()->with('success', "تم اعتماد مستخلص الباطن {$certificate->number} بصافي ".number_format((float) $certificate->net_payable, 2));
    }
}
