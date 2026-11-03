<?php

namespace App\Http\Controllers\Contracts;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FileController;
use App\Models\Contract;
use App\Models\ContractObligation;
use App\Models\ContractTemplate;
use App\Models\CustomerSubscription;
use App\Models\Project;
use App\Models\Supplier;
use App\Rules\AllowedBranch;
use App\Services\Contracts\ContractService;
use App\Services\Workflow\ApprovalService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Commercial contracts: list, edit, versions, approval, renewal, obligations. */
class ContractController extends Controller
{
    public function __construct(protected ContractService $service, protected ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        $f = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|string|max:16', 'kind' => 'nullable|string|max:10', 'side' => 'nullable|in:customer,supplier', 'expiring' => 'nullable|boolean']);

        $contracts = Contract::with(['customer:id,name', 'supplier:id,name'])
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($f['side'] ?? null, fn ($q, $s) => $q->where('party_type', $s))
            ->when($request->boolean('expiring'), fn ($q) => $q->where('status', 'active')->whereBetween('end_date', [today(), today()->addDays(30)]))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))->orWhereHas('supplier', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->orderByRaw("CASE status WHEN 'pending_approval' THEN 0 WHEN 'active' THEN 1 WHEN 'approved' THEN 2 WHEN 'draft' THEN 3 ELSE 4 END")->orderBy('end_date')->latest('id')
            ->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('Contracts/Index', [
            'contracts' => $contracts,
            'filters' => ['search' => $f['search'] ?? '', 'status' => $f['status'] ?? '', 'kind' => $f['kind'] ?? '', 'side' => $f['side'] ?? '', 'expiring' => $request->boolean('expiring')],
            'stats' => [
                'active' => Contract::where('status', 'active')->count(),
                'expiring' => Contract::where('status', 'active')->whereBetween('end_date', [today(), today()->addDays(30)])->count(),
                'pending' => Contract::where('status', 'pending_approval')->count(),
                'value' => (float) Contract::where('status', 'active')->sum('value'),
                'overdue_obligations' => ContractObligation::where('status', 'pending')->whereDate('due_date', '<', today())->whereHas('contract', fn ($q) => $q->whereIn('status', ['active', 'approved']))->count(),
            ],
            'statuses' => Contract::STATUSES, 'kinds' => Contract::KINDS,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Contracts/Form', ['contract' => null] + $this->lookups() + ['customerId' => $request->integer('customer') ?: null]);
    }

    public function edit(Contract $contract): Response
    {
        abort_unless($contract->isEditable(), 403, 'لا يُعدَّل إلا العقد في حالة مسودة أو مرفوض.');

        return Inertia::render('Contracts/Form', ['contract' => $contract->load(['obligations', 'customer:id,name,code,phone,email,tax_number']), 'customerId' => null] + $this->lookups());
    }

    public function store(Request $request)
    {
        [$data, $obligations] = $this->validated($request);
        $contract = $this->service->create($data, $obligations);

        return redirect()->route('contracts.show', $contract->id)->with('success', 'تم إنشاء العقد '.$contract->number);
    }

    public function update(Request $request, Contract $contract)
    {
        [$data, $obligations] = $this->validated($request);
        $this->service->update($contract, $data, $obligations, $request->input('version_note'));

        return redirect()->route('contracts.show', $contract->id)->with('success', 'تم حفظ العقد');
    }

    public function show(Contract $contract): Response
    {
        $contract->load(['customer:id,name,code,phone,email', 'supplier:id,name,code,phone,email', 'obligations', 'versions.author:id,name', 'parent:id,number', 'template:id,name']);

        return Inertia::render('Contracts/Show', [
            'contract' => $contract,
            'renewals' => Contract::where('parent_contract_id', $contract->id)->get(['id', 'number', 'status', 'start_date', 'end_date']),
            'project' => $contract->project_id ? Project::find($contract->project_id, ['id', 'code', 'name']) : null,
            'subscription' => $contract->subscription_id ? CustomerSubscription::find($contract->subscription_id, ['id', 'number']) : null,
            'approvals' => $this->approvals->history($contract),
            'acceptances' => $contract->acceptances()->with('portalUser:id,name')->get(['id', 'contract_id', 'portal_user_id', 'name', 'version', 'accepted_at', 'ip']),
            'canApprove' => $contract->status === 'pending_approval' && auth()->user()->can('contracts.contracts.approve') && ((int) $contract->created_by !== (int) auth()->id() || auth()->user()->isCompanyOwner()),
            'statuses' => Contract::STATUSES, 'kinds' => Contract::KINDS, 'obligationKinds' => ContractObligation::KINDS,
        ]);
    }

    public function destroy(Contract $contract)
    {
        if (! $contract->isEditable()) {
            throw ValidationException::withMessages(['status' => 'لا يُحذف إلا العقد في حالة مسودة أو مرفوض.']);
        }
        $contract->delete();

        return redirect()->route('contracts.index')->with('success', 'تم حذف العقد');
    }

    public function submit(Contract $contract)
    {
        $status = $this->service->submit($contract);

        return back()->with('success', $status === 'pending_approval' ? 'أُرسل العقد للاعتماد' : 'اعتُمد العقد تلقائيًا (أقل من حد الاعتماد)');
    }

    public function approve(Request $request, Contract $contract)
    {
        $this->service->approve($contract, $request->input('comment'));

        return back()->with('success', 'تم اعتماد العقد');
    }

    public function reject(Request $request, Contract $contract)
    {
        $this->service->reject($contract, $request->input('comment'));

        return back()->with('success', 'تم رفض العقد');
    }

    public function activate(Contract $contract)
    {
        $this->service->activate($contract);

        return back()->with('success', 'تم تفعيل العقد');
    }

    public function terminate(Request $request, Contract $contract)
    {
        $data = $request->validate(['reason' => 'required|string|max:500'], [], ['reason' => 'سبب الفسخ']);
        $this->service->terminate($contract, $data['reason']);

        return back()->with('success', 'تم فسخ العقد');
    }

    public function renew(Contract $contract)
    {
        $new = $this->service->renew($contract);

        return redirect()->route('contracts.show', $new->id)->with('success', 'تم التجديد بالعقد '.$new->number);
    }

    public function amend(Request $request, Contract $contract)
    {
        $data = $request->validate(['body' => 'required|string', 'note' => 'nullable|string|max:255'], [], ['body' => 'نص العقد']);
        $this->service->amend($contract, $data['body'], $data['note'] ?? null);

        return back()->with('success', 'حُفظ إصدار جديد من العقد');
    }

    /** Show the contract in the customer's portal, optionally asking for his acceptance of the current version. */
    public function share(Request $request, Contract $contract)
    {
        abort_unless($contract->party_type === 'customer', 422, 'تُشارك عقود العملاء فقط.');
        $data = $request->validate(['shared_with_customer' => 'required|boolean', 'requires_acceptance' => 'nullable|boolean']);
        $contract->update(['shared_with_customer' => $data['shared_with_customer'], 'requires_acceptance' => $data['shared_with_customer'] && ! empty($data['requires_acceptance'])]);

        return back()->with('success', $contract->shared_with_customer ? 'العقد ظاهر الآن للعميل في البوابة' : 'أُخفي العقد عن البوابة');
    }

    public function toggleObligation(ContractObligation $obligation)
    {
        $this->service->completeObligation($obligation, $obligation->status !== 'done');

        return back();
    }

    public function uploadAttachment(Request $request)
    {
        $request->validate(['file' => 'required|file|max:10240|mimes:'.FileController::DOCUMENT_MIMES]);
        $file = $request->file('file');

        return response()->json(['success' => true, 'file' => [
            'name' => $file->getClientOriginalName(), 'url' => FileController::storeForTenant($file, 'contracts/attachments')['url'], 'size' => $file->getSize(), 'type' => $file->getClientMimeType(),
        ]]);
    }

    protected function lookups(): array
    {
        return [
            'templates' => ContractTemplate::where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'body']),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->limit(500)->get(['id', 'name']),
            'projects' => Project::orderBy('code')->limit(300)->get(['id', 'code', 'name']),
            'kinds' => Contract::KINDS, 'obligationKinds' => ContractObligation::KINDS,
        ];
    }

    /** @return array{0: array, 1: array} the contract fields and its obligation rows */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'kind' => 'required|in:sales,purchase,service,lease,other',
            'party_type' => 'required|in:customer,supplier',
            'customer_id' => 'nullable|tenant_exists:customers,id',
            'supplier_id' => 'nullable|tenant_exists:suppliers,id',
            'template_id' => 'nullable|tenant_exists:contract_templates,id',
            'project_id' => 'nullable|tenant_exists:projects,id',
            'branch_id' => ['nullable', 'tenant_exists:branches,id', new AllowedBranch],
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'value' => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|string|max:2000',
            'auto_renew' => 'boolean',
            'renewal_term_months' => 'nullable|integer|min:1|max:120',
            'renewal_notice_days' => 'nullable|integer|min:0|max:365',
            'body' => 'nullable|string',
            'attachments' => 'nullable|array|max:20',
            'attachments.*.name' => 'required|string|max:255',
            'attachments.*.url' => 'required|string|max:500',
            'obligations' => 'nullable|array|max:100',
            'obligations.*.id' => 'nullable|integer',
            'obligations.*.title' => 'required|string|max:255',
            'obligations.*.kind' => 'required|in:payment,delivery,deliverable,milestone,other',
            'obligations.*.due_date' => 'required|date',
            'obligations.*.amount' => 'nullable|numeric|min:0',
            'obligations.*.notes' => 'nullable|string|max:500',
        ], [], ['title' => 'عنوان العقد', 'start_date' => 'تاريخ البداية', 'end_date' => 'تاريخ النهاية', 'obligations.*.title' => 'عنوان الالتزام', 'obligations.*.due_date' => 'تاريخ الالتزام']);

        if (! empty($data['auto_renew']) && (empty($data['end_date']) || empty($data['renewal_term_months']))) {
            throw ValidationException::withMessages(['renewal_term_months' => 'التجديد التلقائي يحتاج تاريخ نهاية ومدة تجديد بالأشهر.']);
        }

        $obligations = $data['obligations'] ?? [];
        unset($data['obligations']);

        $data['value'] ??= 0;
        $data['renewal_notice_days'] ??= 30;
        $data['auto_renew'] = (bool) ($data['auto_renew'] ?? false);

        return [$data, $obligations];
    }
}
