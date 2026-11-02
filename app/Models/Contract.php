<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A commercial contract with a customer or a supplier. */
class Contract extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    protected $fillable = ['company_id', 'branch_id', 'number', 'title', 'kind', 'party_type', 'customer_id', 'supplier_id', 'template_id', 'project_id', 'subscription_id', 'status',
        'start_date', 'end_date', 'value', 'payment_terms', 'auto_renew', 'renewal_term_months', 'renewal_notice_days', 'body', 'version', 'parent_contract_id', 'signed_at',
        'terminated_at', 'termination_reason', 'approved_by', 'approved_at', 'attachments', 'reminder_state', 'created_by'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'signed_at' => 'date', 'terminated_at' => 'date', 'approved_at' => 'datetime', 'value' => 'decimal:2',
        'auto_renew' => 'boolean', 'attachments' => 'array'];

    public const STATUSES = ['draft' => 'مسودة', 'pending_approval' => 'بانتظار الاعتماد', 'rejected' => 'مرفوض', 'approved' => 'معتمد', 'active' => 'ساري', 'expired' => 'منتهٍ', 'terminated' => 'مفسوخ', 'renewed' => 'مجدَّد'];

    public const KINDS = ['sales' => 'بيع', 'purchase' => 'شراء وتوريد', 'service' => 'خدمات', 'lease' => 'إيجار', 'other' => 'أخرى'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_contract_id');
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(ContractObligation::class)->orderBy('due_date');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContractVersion::class)->orderByDesc('version');
    }

    public function partyName(): ?string
    {
        return $this->party_type === 'supplier' ? $this->supplier?->name : $this->customer?->name;
    }

    /** Draft and rejected contracts can still be edited and removed. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }

    public function isLive(): bool
    {
        return $this->status === 'active';
    }
}
