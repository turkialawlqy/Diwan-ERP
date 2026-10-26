<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\LetterOfGuarantee;
use App\Models\ProgressBilling;
use App\Models\Project;
use App\Models\ProjectDailyReport;
use App\Models\ProjectVariation;
use App\Models\Subcontract;
use App\Models\User;
use App\Models\Warehouse;
use App\Rules\AllowedBranch;
use App\Services\Projects\ProjectReport;
use App\Services\Projects\ProjectService;
use App\Services\TenantManager;
use App\Support\BranchOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function __construct(protected ProjectService $projects) {}

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:planning,active,on_hold,completed,closed']);

        return Inertia::render('Projects/Index', [
            'projects' => Project::with(['customer:id,name', 'manager:id,name'])
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")->orWhere('contract_number', 'like', "%{$s}%")))
                ->orderByDesc('id')->paginate($this->perPage($request))->withQueryString(),
            'expiringGuarantees' => LetterOfGuarantee::with('project:id,code,name')->where('status', 'active')
                ->whereDate('expiry_date', '<=', now()->addDays(30))->orderBy('expiry_date')->limit(20)->get(),
            'filters' => $filters,
        ]);
    }

    public function create()
    {
        return Inertia::render('Projects/Create', $this->formOptions() + ['suggestedCode' => $this->peekNumber('project')]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|tenant_exists:customers,id',
            'name' => 'required|string|max:255',
            'contract_number' => 'nullable|string|max:100',
            'manager_id' => 'nullable|tenant_exists:users,id',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'branch_id' => ['nullable', 'tenant_exists:branches,id', new AllowedBranch],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'contract_value' => 'nullable|numeric|min:0',
            'advance_percent' => 'nullable|numeric|min:0|max:100',
            'retention_percent' => 'nullable|numeric|min:0|max:100',
            'location' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
        ] + $this->boqRules(), [
            'customer_id.required' => 'اختر العميل (المالك) من نتائج البحث.',
            'name.required' => 'أدخل اسم المشروع.',
        ] + $this->boqMessages());

        $project = $this->projects->create($validated, $validated['boq'] ?? []);

        return redirect()->route('projects.show', $project->id)->with('success', "تم إنشاء المشروع {$project->code}");
    }

    public function show(Project $project, ProjectReport $report)
    {
        $project->load(['customer:id,name,tax_number', 'manager:id,name', 'warehouse:id,name_ar', 'costCenter:id,code']);

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'summary' => $report->summary($project),
            'boq' => $project->boqItems()->orderBy('sort')->get(),
            'billings' => ProgressBilling::where('project_id', $project->id)->with('invoice:id,invoice_number,zatca_status,payment_status')->orderBy('sequence')->get(),
            'variations' => ProjectVariation::where('project_id', $project->id)->orderBy('id')->get(),
            'subcontracts' => Subcontract::where('project_id', $project->id)->with(['supplier:id,name', 'certificates'])->get(),
            'costs' => $project->costs()->with('product:id,name_ar')->latest('date')->latest('id')->limit(200)->get(),
            'guarantees' => LetterOfGuarantee::where('project_id', $project->id)->orderBy('expiry_date')->get(),
            'dailyReports' => ProjectDailyReport::where('project_id', $project->id)->with('author:id,name')->latest('date')->limit(60)->get(),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name', 'type']),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
        ]);
    }

    public function update(Request $request, Project $project)
    {
        $project->update($request->validate([
            'name' => 'required|string|max:255',
            'status' => 'required|in:planning,active,on_hold,completed,closed',
            'manager_id' => 'nullable|tenant_exists:users,id',
            'warehouse_id' => 'nullable|tenant_exists:warehouses,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'advance_percent' => 'required|numeric|min:0|max:100',
            'retention_percent' => 'required|numeric|min:0|max:100',
            'location' => 'nullable|string|max:255',
        ]));

        return back()->with('success', 'تم تحديث بيانات المشروع');
    }

    public function updateBoq(Request $request, Project $project)
    {
        $validated = $request->validate($this->boqRules(), $this->boqMessages());
        $this->projects->syncBoq($project, $validated['boq'] ?? []);

        return back()->with('success', 'تم حفظ جدول الكميات');
    }

    public function advance(Request $request, Project $project)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'amount' => 'required|numeric|gt:0',
            'payment_method' => 'required|in:cash,bank_transfer,cheque',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
        ]);
        $invoice = $this->projects->receiveAdvance($project, $validated);

        return back()->with('success', "تم تسجيل الدفعة المقدمة وإصدار الفاتورة الضريبية {$invoice->invoice_number}");
    }

    public function releaseRetention(Request $request, Project $project)
    {
        $validated = $request->validate(['amount' => 'required|numeric|gt:0', 'date' => 'required|date']);
        $this->projects->releaseRetention($project, (float) $validated['amount'], $validated['date']);

        return back()->with('success', 'تم الإفراج عن المحتجزات وإضافتها لذمة العميل');
    }

    public function storeVariation(Request $request, Project $project)
    {
        $this->projects->addVariation($project, $request->validate([
            'date' => 'required|date',
            'description' => 'required|string|max:1000',
            'amount' => 'required|numeric',
            'cost_impact' => 'nullable|numeric',
            'time_impact_days' => 'nullable|integer|min:0|max:3650',
        ]));

        return back()->with('success', 'تم تسجيل أمر التغيير');
    }

    public function approveVariation(Project $project, ProjectVariation $variation)
    {
        abort_unless((int) $variation->project_id === (int) $project->id, 404);
        $this->projects->approveVariation($variation);

        return back()->with('success', 'تم اعتماد أمر التغيير وإضافته للعقد');
    }

    public function rejectVariation(Project $project, ProjectVariation $variation)
    {
        abort_unless((int) $variation->project_id === (int) $project->id && $variation->status === 'draft', 404);
        $variation->update(['status' => 'rejected']);

        return back()->with('success', 'تم رفض أمر التغيير');
    }

    public function storeDailyReport(Request $request, Project $project)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'weather' => 'nullable|string|max:50',
            'manpower' => 'nullable|integer|min:0|max:100000',
            'equipment' => 'nullable|string|max:1000',
            'work_done' => 'required|string|max:5000',
            'issues' => 'nullable|string|max:5000',
        ]);

        ProjectDailyReport::updateOrCreate(['project_id' => $project->id, 'date' => $validated['date']], $validated + ['created_by' => auth()->id()]);

        return back()->with('success', 'تم حفظ التقرير اليومي');
    }

    protected function boqRules(): array
    {
        return [
            'boq' => 'nullable|array|max:5000',
            'boq.*.code' => 'required|string|max:50|distinct',
            'boq.*.parent_code' => 'nullable|string|max:50',
            'boq.*.description' => 'required|string|max:1000',
            'boq.*.unit' => 'nullable|string|max:30',
            'boq.*.is_group' => 'nullable|boolean',
            'boq.*.quantity' => 'nullable|numeric|min:0',
            'boq.*.unit_price' => 'nullable|numeric|min:0',
            'boq.*.unit_cost' => 'nullable|numeric|min:0',
        ];
    }

    protected function boqMessages(): array
    {
        return [
            'boq.*.code.required' => 'جدول الكميات: السطر :position بدون رمز.',
            'boq.*.code.distinct' => 'جدول الكميات: رمز السطر :position مكرر.',
            'boq.*.description.required' => 'جدول الكميات: السطر :position بدون وصف.',
            'boq.*.quantity.numeric' => 'جدول الكميات: كمية السطر :position ليست رقماً.',
            'boq.*.unit_price.numeric' => 'جدول الكميات: سعر السطر :position ليس رقماً.',
            'boq.*.unit_cost.numeric' => 'جدول الكميات: تكلفة السطر :position ليست رقماً.',
        ];
    }

    protected function formOptions(): array
    {
        return [
            'managers' => User::where('company_id', app(TenantManager::class)->getTenantId())->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'name_ar']),
            ...BranchOptions::forUser(),
        ];
    }
}
