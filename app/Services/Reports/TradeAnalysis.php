<?php

namespace App\Services\Reports;

use App\Services\ActiveBranch;
use App\Services\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales and purchases broken down by customer / supplier, item, salesperson or branch. Amounts are before VAT,
 * in base currency, and net of the credit notes / purchase returns of the period for the party views.
 * Rows: [id, name, documents, quantity, amount, share (% of the total)] with a `total` row.
 */
class TradeAnalysis
{
    public const SALES = ['customer', 'item', 'salesperson', 'branch'];

    public const PURCHASES = ['supplier', 'item', 'branch'];

    public function sales(string $by, Carbon $from, Carbon $to): array
    {
        $companyId = $this->companyId();
        $range = [$from->toDateString(), $to->toDateString().' 23:59:59'];

        if ($by === 'item') {
            $rows = DB::table('invoice_items as i')->join('invoices as v', 'v.id', '=', 'i.invoice_id')
                ->where('v.company_id', $companyId)->whereBetween('v.invoice_date', $range)->tap(fn ($q) => $this->inBranch($q, 'v.branch_id'))
                ->groupBy('i.product_id', 'i.item_name')
                ->selectRaw('i.product_id as id, i.item_name as name, COUNT(DISTINCT v.id) as documents, SUM(i.quantity) as quantity,
                    SUM(COALESCE(NULLIF(i.subtotal, 0), i.quantity * i.unit_price - COALESCE(i.discount, 0)) * v.exchange_rate) as amount')
                ->get();

            return $this->finish($rows);
        }

        [$column, $names] = match ($by) {
            'salesperson' => ['v.salesperson_id', fn ($ids) => DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')],
            'branch' => ['v.branch_id', fn ($ids) => DB::table('branches')->whereIn('id', $ids)->pluck('name_ar', 'id')],
            default => ['v.customer_id', fn ($ids) => DB::table('customers')->whereIn('id', $ids)->pluck('name', 'id')],
        };

        $sales = DB::table('invoices as v')->where('v.company_id', $companyId)->whereBetween('v.invoice_date', $range)->tap(fn ($q) => $this->inBranch($q, 'v.branch_id'))
            ->groupBy($column)->selectRaw("{$column} as id, COUNT(v.id) as documents, SUM(v.subtotal * v.exchange_rate) as amount")->get()->keyBy(fn ($r) => (string) $r->id);
        $returns = DB::table('credit_notes as n')->leftJoin('invoices as v', 'v.id', '=', 'n.invoice_id')->where('n.company_id', $companyId)
            ->whereIn('n.status', ['approved', 'refunded'])->whereBetween('n.credit_note_date', $range)->tap(fn ($q) => $this->inBranch($q, 'v.branch_id'))
            ->groupBy($by === 'customer' ? 'n.customer_id' : $column)->selectRaw(($by === 'customer' ? 'n.customer_id' : $column).' as id, SUM(n.subtotal * n.exchange_rate) as amount')->pluck('amount', 'id');

        $ids = $sales->keys()->merge($returns->keys())->map(fn ($id) => (string) $id)->unique()->values();
        $label = $names($ids->filter(fn ($id) => $id !== '')->values());
        $none = ['salesperson' => 'بدون مندوب', 'branch' => 'بدون فرع'][$by] ?? 'بدون عميل';
        $rows = $ids->map(fn ($id) => (object) [
            'id' => $id === '' ? null : (int) $id, 'name' => $label[$id] ?? $none, 'documents' => (int) ($sales[$id]->documents ?? 0), 'quantity' => null,
            'amount' => (float) ($sales[$id]->amount ?? 0) - (float) ($returns[$id] ?? 0),
        ]);

        return $this->finish($rows);
    }

    public function purchases(string $by, Carbon $from, Carbon $to): array
    {
        $companyId = $this->companyId();
        $range = [$from->toDateString(), $to->toDateString().' 23:59:59'];

        if ($by === 'item') {
            $rows = DB::table('purchase_invoice_items as i')->join('purchase_invoices as p', 'p.id', '=', 'i.purchase_invoice_id')
                ->where('p.company_id', $companyId)->whereBetween('p.invoice_date', $range)->tap(fn ($q) => $this->inBranch($q, 'p.branch_id'))
                ->groupBy('i.product_id', 'i.item_name')
                ->selectRaw('i.product_id as id, i.item_name as name, COUNT(DISTINCT p.id) as documents, SUM(i.quantity) as quantity,
                    SUM((i.quantity * i.unit_price - COALESCE(i.discount, 0)) * p.exchange_rate) as amount')
                ->get();

            return $this->finish($rows);
        }

        $column = $by === 'branch' ? 'p.branch_id' : 'p.supplier_id';
        $bought = DB::table('purchase_invoices as p')->where('p.company_id', $companyId)->whereBetween('p.invoice_date', $range)->tap(fn ($q) => $this->inBranch($q, 'p.branch_id'))
            ->groupBy($column)->selectRaw("{$column} as id, COUNT(p.id) as documents, SUM(p.subtotal * p.exchange_rate) as amount")->get()->keyBy(fn ($r) => (string) $r->id);
        $returned = DB::table('purchase_returns as r')->leftJoin('purchase_invoices as p', 'p.id', '=', 'r.purchase_invoice_id')->where('r.company_id', $companyId)
            ->whereNotIn('r.status', ['draft', 'cancelled'])->whereBetween('r.return_date', $range)->tap(fn ($q) => $this->inBranch($q, 'p.branch_id'))
            ->groupBy($by === 'branch' ? 'p.branch_id' : 'r.supplier_id')->selectRaw(($by === 'branch' ? 'p.branch_id' : 'r.supplier_id').' as id, SUM(r.subtotal * COALESCE(p.exchange_rate, 1)) as amount')->pluck('amount', 'id');

        $ids = $bought->keys()->merge($returned->keys())->map(fn ($id) => (string) $id)->unique()->values();
        $real = $ids->filter(fn ($id) => $id !== '')->values();
        $label = $by === 'branch' ? DB::table('branches')->whereIn('id', $real)->pluck('name_ar', 'id') : DB::table('suppliers')->whereIn('id', $real)->pluck('name', 'id');
        $none = $by === 'branch' ? 'بدون فرع' : 'بدون مورد';
        $rows = $ids->map(fn ($id) => (object) [
            'id' => $id === '' ? null : (int) $id, 'name' => $label[$id] ?? $none, 'documents' => (int) ($bought[$id]->documents ?? 0), 'quantity' => null,
            'amount' => (float) ($bought[$id]->amount ?? 0) - (float) ($returned[$id] ?? 0),
        ]);

        return $this->finish($rows);
    }

    protected function finish($rows): array
    {
        $rows = collect($rows)->map(fn ($r) => ['id' => $r->id, 'name' => (string) $r->name, 'documents' => (int) $r->documents, 'quantity' => $r->quantity === null ? null : round((float) $r->quantity, 2), 'amount' => round((float) $r->amount, 2)])
            ->sortByDesc('amount')->values();
        $total = round($rows->sum('amount'), 2);

        return [
            'rows' => $rows->map(fn ($r) => $r + ['share' => $total != 0.0 ? round($r['amount'] / $total * 100, 1) : null])->all(),
            'total' => ['documents' => $rows->sum('documents'), 'quantity' => $rows->contains(fn ($r) => $r['quantity'] !== null) ? round((float) $rows->sum('quantity'), 2) : null, 'amount' => $total],
        ];
    }

    /** Raw queries bypass the branch scope: keep them to the branches the user is viewing. */
    protected function inBranch($query, string $column)
    {
        $ids = app(ActiveBranch::class)->scopeIds();

        return $ids === null ? $query : $query->where(fn ($w) => $w->whereIn($column, $ids)->orWhereNull($column));
    }

    protected function companyId(): int
    {
        return (int) (app(TenantManager::class)->getTenantId() ?? auth()->user()?->company_id);
    }
}
