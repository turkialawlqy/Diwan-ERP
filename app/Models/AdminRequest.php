<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A request summoning employees (possibly of several departments) to attend. */
class AdminRequest extends Model
{
    use Auditable, BelongsToCompany, BranchScoped, SoftDeletes;

    protected $fillable = ['company_id', 'branch_id', 'number', 'title', 'body', 'attend_at', 'location', 'requested_by', 'status', 'sent_at', 'completed_at'];

    protected $casts = ['attend_at' => 'datetime', 'sent_at' => 'datetime', 'completed_at' => 'datetime'];

    public const STATUSES = ['draft' => 'مسودة', 'new' => 'جديد', 'processing' => 'قيد المعالجة', 'completed' => 'مكتمل', 'completed_partial' => 'مكتمل مع تعذر'];

    public function lines(): HasMany
    {
        return $this->hasMany(AdminRequestLine::class)->orderBy('id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
