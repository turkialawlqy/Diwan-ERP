<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Rules\BranchBox;
use App\Services\Finance\PaymentRequestService;
use App\Services\TenantManager;
use App\Services\Workflow\ApprovalService;
use App\Support\PickerOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** «طلبات الصرف»: request → approval → carried out as an expense voucher or supplier payment. */
class PaymentRequestController extends Controller
{
    protected const INVOICE_COLUMNS = ['id', 'supplier_id', 'invoice_number', 'remaining_amount'];

    public function __construct(protected PaymentRequestService $service, protected ApprovalService $approvals) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|string|max:30']);

        return Inertia::render('Finance/PaymentRequests/Index', [
            'requests' => PaymentRequest::with(['supplier:id,name', 'employee:id,name', 'requester:id,name'])
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('request_number', 'like', "%{$s}%")->orWhere('purpose', 'like', "%{$s}%")->orWhere('payee_name', 'like', "%{$s}%")))
                ->inActiveYear('request_date')->orderByDesc('request_date')->orderByDesc('id')->paginate($this->perPage($request))->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Finance/PaymentRequests/Form', $this->options() + ['paymentRequest' => null]);
    }

    public function edit(PaymentRequest $paymentRequest): Response
    {
        abort_unless(in_array($paymentRequest->status, PaymentRequest::EDITABLE, true), 403);

        return Inertia::render('Finance/PaymentRequests/Form', $this->options($paymentRequest) + ['paymentRequest' => $paymentRequest]);
    }

    /** The form's list of open invoices: the chosen supplier's or everyone's, matched on the number and amount it shows. */
    public function openInvoices(Request $request): JsonResponse
    {
        $request->validate(['supplier_id' => 'nullable|tenant_exists:suppliers,id']);
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 100);

        return response()->json(['data' => $this->unpaidInvoices($request->integer('supplier_id') ?: null)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('invoice_number', 'like', "%{$q}%")->orWhere('remaining_amount', 'like', "%{$q}%")))
            ->limit(PickerOptions::FIRST)
            ->get(self::INVOICE_COLUMNS)]);
    }

    public function store(Request $request)
    {
        $paymentRequest = $this->service->create($this->validated($request));
        if ($request->boolean('send_for_approval')) {
            $this->service->submit($paymentRequest);
        }

        return redirect()->route('finance.payment-requests.show', $paymentRequest->id)->with('success', "تم إنشاء طلب الصرف {$paymentRequest->request_number}");
    }

    public function update(Request $request, PaymentRequest $paymentRequest)
    {
        $this->service->update($paymentRequest, $this->validated($request, $paymentRequest));
        if ($request->boolean('send_for_approval')) {
            $this->service->submit($paymentRequest);
        }

        return redirect()->route('finance.payment-requests.show', $paymentRequest->id)->with('success', 'تم حفظ طلب الصرف');
    }

    public function show(PaymentRequest $paymentRequest): Response
    {
        $paymentRequest->load(['supplier:id,name', 'employee:id,name', 'requester:id,name', 'account:id,code,name_ar']);

        return Inertia::render('Finance/PaymentRequests/Show', [
            'paymentRequest' => $paymentRequest,
            'approvals' => $this->approvals->history($paymentRequest),
            'executeUrl' => $paymentRequest->status === 'approved'
                ? ($paymentRequest->kind === 'expense' ? route('finance.expenses.create', ['payment_request' => $paymentRequest->id]) : route('purchases.payments.create', ['payment_request' => $paymentRequest->id]))
                : null,
        ]);
    }

    public function submit(PaymentRequest $paymentRequest)
    {
        $status = $this->service->submit($paymentRequest);

        return back()->with('success', $status === 'approved' ? 'اعتُمد الطلب تلقائيًا (أقل من حد الاعتماد)' : 'أُرسل الطلب للاعتماد');
    }

    public function approve(Request $request, PaymentRequest $paymentRequest)
    {
        $this->service->approve($paymentRequest, $request->input('comment'));

        return back()->with('success', 'تم اعتماد طلب الصرف');
    }

    public function reject(Request $request, PaymentRequest $paymentRequest)
    {
        $this->service->reject($paymentRequest, $request->input('comment'));

        return back()->with('success', 'تم رفض طلب الصرف');
    }

    public function cancel(PaymentRequest $paymentRequest)
    {
        $this->service->cancel($paymentRequest);

        return back()->with('success', 'تم إلغاء طلب الصرف');
    }

    public function destroy(PaymentRequest $paymentRequest)
    {
        abort_unless($paymentRequest->status === 'draft', 403);
        $paymentRequest->delete();

        return redirect()->route('finance.payment-requests.index')->with('success', 'تم حذف المسودة');
    }

    protected function validated(Request $request, ?PaymentRequest $paymentRequest = null): array
    {
        return $request->validate([
            'request_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:request_date',
            'kind' => 'required|in:'.implode(',', PaymentRequest::KINDS),
            'payee_type' => 'required|in:supplier,employee,other',
            'supplier_id' => 'nullable|tenant_exists:suppliers,id',
            'employee_id' => 'nullable|tenant_exists:employees,id',
            'payee_name' => 'nullable|string|max:255',
            'purpose' => 'required|string|max:500',
            // What the form lists: a posting (non-parent) expense account of the company.
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where(fn ($q) => $q->where('company_id', app(TenantManager::class)->getTenantId())->where('type', 'expense')->where('is_parent', false))],
            'purchase_invoice_id' => 'nullable|tenant_exists:purchase_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,bank_transfer,cheque',
            // A box of the request's branch (a new request: the top bar's).
            'bank_account_id' => ['nullable', BranchBox::of($paymentRequest?->branch_id)],
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
            'project_id' => 'nullable|tenant_exists:projects,id',
            'notes' => 'nullable|string|max:2000',
        ], ['account_id.exists' => 'اختر حساب مصروفات فرعيًا.']);
    }

    protected function options(?PaymentRequest $editing = null): array
    {
        return [
            'suppliers' => PickerOptions::from(Supplier::where('status', 'active')->orderBy('name'), ['id', 'name'], [$editing?->supplier_id]),
            'employees' => Employee::where('status', '!=', 'terminated')->orderBy('name')->limit(500)->get(['id', 'name']),
            'expenseAccounts' => Account::where('type', 'expense')->where('is_parent', false)->orderBy('code')->get(['id', 'code', 'name_ar']),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name']),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'unpaidInvoices' => PickerOptions::from($this->unpaidInvoices($editing?->supplier_id), self::INVOICE_COLUMNS, [$editing?->purchase_invoice_id]),
        ];
    }

    protected function unpaidInvoices(?int $supplierId = null): Builder
    {
        return PurchaseInvoice::where('remaining_amount', '>', 0)->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))->orderBy('id');
    }
}
