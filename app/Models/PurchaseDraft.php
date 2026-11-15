<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A stock-in waiting for accounting: its goods receipt is already in stock, the supplier invoice is not yet written. */
class PurchaseDraft extends Model
{
    use BelongsToCompany, BranchScoped;

    public const STATUSES = ['pending' => 'بانتظار الفوترة', 'completed' => 'فُوترت', 'cancelled' => 'أُلغيت'];

    protected $fillable = ['company_id', 'branch_id', 'number', 'goods_receipt_id', 'warehouse_id', 'status', 'notes', 'purchase_invoice_id', 'created_by', 'completed_by', 'completed_at'];

    protected $casts = ['completed_at' => 'datetime'];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
