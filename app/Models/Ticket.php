<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer support ticket with SLA deadlines. */
class Ticket extends Model
{
    use Auditable, BelongsToCompany, BranchScoped;

    protected $fillable = ['sla_paused_at', 'company_id', 'branch_id', 'number', 'customer_id', 'contact_name', 'contact_phone', 'contact_email', 'property_unit_id', 'subject', 'description', 'channel', 'priority', 'status',
        'category_id', 'assigned_to', 'first_response_due_at', 'resolution_due_at', 'first_response_at', 'resolved_at', 'closed_at', 'response_breached', 'resolution_breached',
        'rating', 'rating_comment', 'subscription_id', 'invoice_id', 'project_id', 'equipment_id', 'created_by', 'portal_user_id'];

    protected $casts = ['sla_paused_at' => 'datetime', 'first_response_due_at' => 'datetime', 'resolution_due_at' => 'datetime', 'first_response_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime',
        'response_breached' => 'boolean', 'resolution_breached' => 'boolean'];

    public const STATUSES = ['open' => 'مفتوحة', 'pending' => 'بانتظار العميل', 'resolved' => 'محلولة', 'closed' => 'مغلقة'];

    public const PRIORITIES = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة'];

    public const CHANNELS = ['phone' => 'هاتف', 'email' => 'بريد', 'whatsapp' => 'واتساب', 'web' => 'الموقع', 'visit' => 'زيارة', 'other' => 'أخرى'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupportCategory::class, 'category_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['open', 'pending'], true);
    }

    /** Past a deadline that has not been met yet. */
    public function isOverdue(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return ($this->resolution_due_at && $this->resolution_due_at->isPast())
            || (! $this->first_response_at && $this->first_response_due_at && $this->first_response_due_at->isPast());
    }
}
