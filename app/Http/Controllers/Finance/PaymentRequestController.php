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
use App\Services\Finance\PaymentRequestService;
use App\Services\Workflow\ApprovalService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «طلبات الصرف»: request → approval → carried out as an expense voucher or supplier payment. */
class PaymentRequestController extends Controller
{
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

        return Inertia::render('Finance/PaymentRequests/Form', $this->options() + ['paymentRequest' => $paymentRequest]);
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
        $this->service->update($paymentRequest, $this->validated($request));
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

    protected function validated(Request $request): array
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
            'account_id' => 'nullable|tenant_exists:accounts,id',
            'purchase_invoice_id' => 'nullable|tenant_exists:purchase_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card,bank_transfer,cheque',
            'bank_account_id' => 'nullable|tenant_exists:bank_accounts,id',
            'cost_center_id' => 'nullable|tenant_exists:cost_centers,id',
            'project_id' => 'nullable|tenant_exists:projects,id',
            'notes' => 'nullable|string|max:2000',
        ]);
    }

    protected function options(): array
    {
        return [
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->limit(500)->get(['id', 'name']),
            'employees' => Employee::where('status', '!=', 'terminated')->orderBy('name')->limit(500)->get(['id', 'name']),
            'expenseAccounts' => Account::where('type', 'expense')->where('is_parent', false)->orderBy('code')->get(['id', 'code', 'name_ar']),
            'bankAccounts' => BankAccount::where('is_active', true)->get(['id', 'name']),
            'costCenters' => CostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_ar']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'unpaidInvoices' => PurchaseInvoice::where('remaining_amount', '>', 0)->get(['id', 'supplier_id', 'invoice_number', 'remaining_amount']),
        ];
    }
}
