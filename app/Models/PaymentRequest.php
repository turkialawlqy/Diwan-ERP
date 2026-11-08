<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRequest extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    public const KINDS = ['expense', 'supplier_payment'];

    public const EDITABLE = ['draft', 'rejected'];

    protected $fillable = [
        'company_id', 'branch_id', 'request_number', 'request_date', 'due_date', 'kind', 'payee_type', 'supplier_id', 'employee_id', 'payee_name', 'purpose', 'account_id',
        'purchase_invoice_id', 'amount', 'payment_method', 'bank_account_id', 'cost_center_id', 'project_id', 'status', 'requested_by', 'approved_by', 'approved_at',
        'paid_at', 'paid_type', 'paid_id', 'notes',
    ];

    protected $appends = ['payee_label'];

    protected $casts = ['request_date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:2', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function getPayeeLabelAttribute(): string
    {
        return $this->payeeLabel();
    }

    public function payeeLabel(): string
    {
        return (string) ($this->supplier?->name ?? $this->employee?->name ?? $this->payee_name ?? '');
    }
}
