<?php

namespace App\Models;

use App\Services\Accounting\BranchBalances;
use App\Services\Accounting\CashFlowBalances;
use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalEntry extends Model
{
    use Auditable, BelongsToCompany, BranchScoped, HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'entry_number',
        'date',
        'reference',
        'description',
        'total_debit',
        'total_credit',
        'status',
        'entry_type',
        'source_type',
        'source_id',
        'fiscal_period_id',
        'reversal_of_id',
        'reversed_by_id',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected static function booted(): void
    {
        // An entry already in the ledger, filed under another branch, takes its amounts along.
        static::updated(function (JournalEntry $entry) {
            if ($entry->wasChanged('branch_id') && in_array($entry->getOriginal('status'), [self::STATUS_POSTED, self::STATUS_REVERSED], true)) {
                app(BranchBalances::class)->moveEntry($entry, $entry->getOriginal('branch_id'), $entry->branch_id);
                app(CashFlowBalances::class)->moveEntry($entry, $entry->getOriginal('branch_id'), $entry->branch_id);
            }
        });
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_by_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
