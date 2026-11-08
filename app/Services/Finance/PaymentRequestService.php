<?php

namespace App\Services\Finance;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Notifications\ErpAlert;
use App\Services\Documents\DocumentNumberService;
use App\Services\Workflow\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment requests: someone asks for money to be paid out, an approver decides, and the payment is then made as an
 * ordinary expense voucher or supplier payment which is tied back to the request. Approval uses the amount rules of
 * the «طلبات الصرف» type (no rule: approved on submit).
 */
class PaymentRequestService
{
    public function __construct(protected ApprovalService $approvals, protected DocumentNumberService $numbers) {}

    public function create(array $data): PaymentRequest
    {
        return DB::transaction(function () use ($data) {
            $date = Carbon::parse($data['request_date']);

            return PaymentRequest::create($this->attributes($data) + [
                'request_number' => $this->numbers->next('payment_request', $date),
                'status' => 'draft',
                'requested_by' => auth()->id(),
            ]);
        });
    }

    public function update(PaymentRequest $request, array $data): PaymentRequest
    {
        $this->assertEditable($request);
        $request->update($this->attributes($data) + ['status' => 'draft']);

        return $request;
    }

    /** Send for approval; without a matching rule it is approved at once. */
    public function submit(PaymentRequest $request): string
    {
        $this->assertEditable($request);
        $status = $this->approvals->submit($request, 'payment_request', (float) $request->amount, 'approved');
        if ($status === 'pending_approval') {
            $this->notifyApprovers($request);
        }

        return $status;
    }

    public function approve(PaymentRequest $request, ?string $comment = null): void
    {
        $this->approvals->approve($request, 'approved', $comment);
    }

    public function reject(PaymentRequest $request, ?string $comment = null): void
    {
        $this->approvals->reject($request, $comment);
    }

    public function cancel(PaymentRequest $request): void
    {
        if (! in_array($request->status, ['draft', 'pending_approval', 'approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'لا يمكن إلغاء طلب مدفوع أو ملغى.']);
        }
        $request->update(['status' => 'cancelled']);
    }

    /**
     * The approved request a voucher is about to carry out, checked against what the voucher does; null when the
     * voucher is not made for a request. The request's approval stands in for the approval gate of the voucher.
     */
    public function covering(?int $requestId, string $kind, float $amount, ?int $supplierId = null): ?PaymentRequest
    {
        if (! $requestId) {
            return null;
        }
        $request = PaymentRequest::lockForUpdate()->find($requestId);
        $fail = fn (string $message) => throw ValidationException::withMessages(['payment_request_id' => $message]);

        if (! $request) {
            $fail('طلب الصرف غير موجود.');
        }
        if ($request->status !== 'approved') {
            $fail('طلب الصرف '.$request->request_number.' ليس معتمدًا أو سبق تنفيذه.');
        }
        if ($request->kind !== $kind) {
            $fail('نوع السند لا يطابق نوع طلب الصرف.');
        }
        if ($amount > (float) $request->amount + 0.005) {
            $fail('المبلغ أكبر من المبلغ المعتمد في طلب الصرف ('.number_format((float) $request->amount, 2).').');
        }
        if ($kind === 'supplier_payment' && $request->supplier_id && (int) $request->supplier_id !== (int) $supplierId) {
            $fail('المورد لا يطابق المورد في طلب الصرف.');
        }

        return $request;
    }

    public function markPaid(PaymentRequest $request, Model $voucher): void
    {
        $request->update(['status' => 'paid', 'paid_at' => now(), 'paid_type' => $voucher->getMorphClass(), 'paid_id' => $voucher->getKey()]);
    }

    /** Values for the voucher form that carries the request out. */
    public function prefill(PaymentRequest $request): array
    {
        return $request->kind === 'expense'
            ? ['payment_request_id' => $request->id, 'title' => $request->purpose, 'account_id' => $request->account_id, 'amount' => (float) $request->amount, 'cost_center_id' => $request->cost_center_id,
                'project_id' => $request->project_id, 'bank_account_id' => $request->bank_account_id, 'payment_method' => $request->payment_method, 'vendor_name' => $request->payeeLabel(), 'notes' => $request->notes]
            : ['payment_request_id' => $request->id, 'supplier_id' => $request->supplier_id, 'purchase_invoice_id' => $request->purchase_invoice_id, 'amount' => (float) $request->amount,
                'payment_method' => $request->payment_method, 'bank_account_id' => $request->bank_account_id, 'notes' => trim($request->purpose.' — '.$request->request_number)];
    }

    protected function attributes(array $data): array
    {
        $kind = $data['kind'];
        if ($kind === 'supplier_payment' && empty($data['supplier_id'])) {
            throw ValidationException::withMessages(['supplier_id' => 'اختر المورد لطلب سند الصرف.']);
        }
        $payeeType = $data['payee_type'] ?? 'other';

        return [
            'request_date' => $data['request_date'], 'due_date' => $data['due_date'] ?? null, 'kind' => $kind, 'payee_type' => $payeeType,
            'supplier_id' => $payeeType === 'supplier' || $kind === 'supplier_payment' ? ($data['supplier_id'] ?? null) : null,
            'employee_id' => $payeeType === 'employee' ? ($data['employee_id'] ?? null) : null,
            'payee_name' => $data['payee_name'] ?? null, 'purpose' => $data['purpose'],
            'account_id' => $kind === 'expense' ? ($data['account_id'] ?? null) : null,
            'purchase_invoice_id' => $kind === 'supplier_payment' ? ($data['purchase_invoice_id'] ?? null) : null,
            'amount' => $data['amount'], 'payment_method' => $data['payment_method'] ?? 'bank_transfer', 'bank_account_id' => $data['bank_account_id'] ?? null,
            'cost_center_id' => $data['cost_center_id'] ?? null, 'project_id' => $data['project_id'] ?? null, 'notes' => $data['notes'] ?? null,
        ];
    }

    protected function assertEditable(PaymentRequest $request): void
    {
        if (! in_array($request->status, PaymentRequest::EDITABLE, true)) {
            throw ValidationException::withMessages(['status' => 'لا يمكن تعديل الطلب في حالته الحالية.']);
        }
    }

    /** An instant alert to everyone who may decide the request. */
    protected function notifyApprovers(PaymentRequest $request): void
    {
        $url = route('finance.payment-requests.show', $request->id, false);
        User::where('company_id', $request->company_id)->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->id !== auth()->id() && $u->can('finance.payment_requests.approve'))
            ->each(fn (User $u) => $u->notify(new ErpAlert('payment_request', 'طلب صرف بانتظار اعتمادك', "{$request->request_number}: {$request->purpose} — ".number_format((float) $request->amount, 2), $url)));
    }
}
