<?php

namespace App\Services\Projects;

use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProgressBilling;
use App\Models\Project;
use App\Models\ProjectBoqItem;
use App\Models\ProjectCost;
use App\Models\ProjectVariation;
use App\Models\Subcontract;
use App\Models\SubcontractCertificate;
use App\Models\Supplier;
use App\Services\Accounting\DocumentPoster;
use App\Services\Accounting\PostingService;
use App\Services\Documents\DocumentNumberService;
use App\Services\Inventory\DocumentStock;
use App\Services\Inventory\StockService;
use App\Services\Sales\InvoiceService;
use App\Services\Tax\TaxProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contracting: contracts with BOQ, variations, client advances and progress
 * billings (advance recovery + retention, issued as ZATCA tax invoices),
 * subcontracts and certificates, and project costs posted to the project's
 * cost centre.
 *
 * Journal templates
 *   Advance received      Dr Cash                     Cr Customer advances, VAT output
 *   Progress billing      Dr Receivables (net due), Customer advances (recovery), Retention receivable
 *                         Cr Contract revenue (work value), VAT output
 *   Retention release     Dr Receivables              Cr Retention receivable
 *   Subcontract advance   Dr Supplier advances        Cr Cash
 *   Subcontract cert.     Dr Subcontractor costs, VAT input
 *                         Cr Payables (net), Supplier advances (recovery), Retention payable
 *   Material issue        Dr Project costs            Cr Inventory      (return: reversed)
 *   Labour / equipment    Dr Project costs (+VAT in)  Cr Cash / Payables / Accrued expenses
 */
class ProjectService
{
    public const TOLERANCE = 0.00005;

    public function __construct(
        protected DocumentNumberService $numbers,
        protected PostingService $posting,
        protected DocumentPoster $poster,
        protected InvoiceService $invoices,
        protected DocumentStock $documentStock,
        protected StockService $stock,
    ) {}

    // ----- Contract & BOQ -----

    public function create(array $data, array $boq = []): Project
    {
        return DB::transaction(function () use ($data, $boq) {
            $code = $this->numbers->next('project');
            $root = CostCenter::firstOrCreate(['code' => 'PRJ'], ['name_ar' => 'المشاريع', 'name_en' => 'Projects', 'is_active' => true]);
            $costCenter = CostCenter::create(['parent_id' => $root->id, 'code' => $code, 'name_ar' => $data['name'], 'is_active' => true]);

            $project = Project::create([
                'branch_id' => $data['branch_id'] ?? auth()->user()?->defaultBranchId(),
                'customer_id' => $data['customer_id'],
                'cost_center_id' => $costCenter->id,
                'manager_id' => $data['manager_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'code' => $code,
                'name' => $data['name'],
                'contract_number' => $data['contract_number'] ?? null,
                'status' => $data['status'] ?? 'active',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'contract_value' => 0,
                'advance_percent' => $data['advance_percent'] ?? 0,
                'retention_percent' => $data['retention_percent'] ?? 0,
                'location' => $data['location'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            $this->syncBoq($project, $boq);
            if (! $boq && ! empty($data['contract_value'])) {
                $project->update(['contract_value' => round((float) $data['contract_value'], 2)]);
            }

            return $project;
        });
    }

    /**
     * Replace the BOQ (allowed until the first billing). Items:
     * code, description, unit?, quantity, unit_price, unit_cost?, is_group?, parent_code?
     */
    public function syncBoq(Project $project, array $items): void
    {
        if (ProgressBilling::where('project_id', $project->id)->exists()) {
            throw ValidationException::withMessages(['boq' => 'لا يمكن تعديل جدول الكميات بعد إصدار مستخلصات. استخدم أوامر التغيير.']);
        }

        DB::transaction(function () use ($project, $items) {
            ProjectBoqItem::where('project_id', $project->id)->whereNull('variation_id')->delete();
            $byCode = [];
            foreach (array_values($items) as $i => $item) {
                $row = $project->boqItems()->create([
                    'parent_id' => isset($item['parent_code']) ? ($byCode[$item['parent_code']] ?? null) : null,
                    'code' => $item['code'],
                    'description' => $item['description'],
                    'unit' => $item['unit'] ?? null,
                    'quantity' => ! empty($item['is_group']) ? 0 : (float) $item['quantity'],
                    'unit_price' => ! empty($item['is_group']) ? 0 : (float) $item['unit_price'],
                    'unit_cost' => (float) ($item['unit_cost'] ?? 0),
                    'is_group' => ! empty($item['is_group']),
                    'sort' => $i,
                ]);
                $byCode[$item['code']] = $row->id;
            }
            $this->refreshContractValue($project);
        });
    }

    public function refreshContractValue(Project $project): void
    {
        $value = ProjectBoqItem::where('project_id', $project->id)->where('is_group', false)
            ->get(['quantity', 'unit_price'])->sum(fn ($i) => round((float) $i->quantity * (float) $i->unit_price, 2));

        if ($value > 0) {
            $project->update(['contract_value' => round($value, 2)]);
        }
    }

    public function addVariation(Project $project, array $data): ProjectVariation
    {
        $count = ProjectVariation::where('project_id', $project->id)->count();

        return ProjectVariation::create([
            'project_id' => $project->id,
            'number' => sprintf('%s-VO-%02d', $project->code, $count + 1),
            'date' => $data['date'],
            'description' => $data['description'],
            'amount' => round((float) $data['amount'], 2),
            'cost_impact' => round((float) ($data['cost_impact'] ?? 0), 2),
            'time_impact_days' => (int) ($data['time_impact_days'] ?? 0),
            'status' => 'draft',
        ]);
    }

    /**
     * An approved variation becomes a billable BOQ line and changes the
     * contract value (and the end date by its time impact).
     */
    public function approveVariation(ProjectVariation $variation): void
    {
        DB::transaction(function () use ($variation) {
            $variation = ProjectVariation::whereKey($variation->id)->lockForUpdate()->firstOrFail();
            if ($variation->status !== 'draft') {
                throw ValidationException::withMessages(['variation' => 'أمر التغيير ليس مسودة.']);
            }

            $project = Project::whereKey($variation->project_id)->lockForUpdate()->firstOrFail();
            if ((float) $variation->amount != 0.0) {
                $project->boqItems()->create([
                    'variation_id' => $variation->id,
                    'code' => $variation->number,
                    'description' => 'أمر تغيير: '.$variation->description,
                    'unit' => 'LS',
                    'quantity' => (float) $variation->amount < 0 ? -1 : 1,
                    'unit_price' => abs((float) $variation->amount),
                    'unit_cost' => abs((float) $variation->cost_impact),
                    'sort' => 10000 + $variation->id,
                ]);
            }

            $project->update([
                'contract_value' => round((float) $project->contract_value + (float) $variation->amount, 2),
                'end_date' => $project->end_date && $variation->time_impact_days ? $project->end_date->copy()->addDays($variation->time_impact_days) : $project->end_date,
            ]);
            $variation->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });
    }

    // ----- Client side -----

    /**
     * Advance payment from the client: a tax invoice (VAT due on receipt) settled at once.
     */
    public function receiveAdvance(Project $project, array $data): Invoice
    {
        return DB::transaction(function () use ($project, $data) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $net = round((float) $data['amount'], 2);

            $invoice = $this->invoices->create([
                'customer_id' => $project->customer_id,
                'branch_id' => $project->branch_id,
                'invoice_date' => $data['date'],
                'payment_method' => $data['payment_method'] ?? 'bank_transfer',
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'payment_status' => 'paid',
                'reference_number' => $project->code,
                'notes' => 'دفعة مقدمة - '.$project->name,
            ], [[
                'item_name' => "دفعة مقدمة على عقد مشروع {$project->code} - {$project->name}",
                'quantity' => 1,
                'unit_price' => $net,
                'tax_rate' => TaxProfile::rate(),
            ]], ['issue_stock' => false, 'post' => false, 'check_credit' => false]);

            $this->posting->post([
                'date' => $invoice->invoice_date,
                'reference' => $invoice->invoice_number,
                'description' => "دفعة مقدمة من العميل - مشروع {$project->code}",
                'source' => $invoice,
                'lines' => array_filter([
                    ['account_id' => $this->poster->cashAccount($invoice->bank_account_id, $invoice->payment_method), 'debit' => (float) $invoice->total],
                    ['role' => 'customer_advances', 'credit' => (float) $invoice->subtotal, 'party_type' => 'customer', 'party_id' => $project->customer_id, 'cost_center_id' => $project->cost_center_id],
                    (float) $invoice->tax_amount > 0 ? ['role' => 'vat_output', 'credit' => (float) $invoice->tax_amount] : null,
                ]),
            ]);

            $project->update(['advance_received' => round((float) $project->advance_received + (float) $invoice->subtotal, 2)]);

            return $invoice;
        });
    }

    /**
     * Draft progress billing (interim payment certificate) from cumulative
     * or current quantities per BOQ line.
     *
     * @param  array  $lines  [boq_item_id, current_quantity]
     */
    public function draftBilling(Project $project, array $data, array $lines): ProgressBilling
    {
        return DB::transaction(function () use ($project, $data, $lines) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (ProgressBilling::where('project_id', $project->id)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['billing' => 'يوجد مستخلص مسودة لم يُعتمد بعد لهذا المشروع.']);
            }

            $sequence = (int) ProgressBilling::where('project_id', $project->id)->max('sequence') + 1;
            $billing = ProgressBilling::create([
                'project_id' => $project->id,
                'number' => sprintf('%s-IPC-%02d', $project->code, $sequence),
                'sequence' => $sequence,
                'billing_date' => $data['billing_date'],
                'period_from' => $data['period_from'] ?? null,
                'period_to' => $data['period_to'] ?? null,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $current = 0.0;
            foreach ($lines as $line) {
                $quantity = (float) $line['current_quantity'];
                if ($quantity == 0.0) {
                    continue;
                }
                $item = ProjectBoqItem::where('project_id', $project->id)->where('is_group', false)->whereKey($line['boq_item_id'])->firstOrFail();
                if ((float) $item->quantity < 0) {
                    throw ValidationException::withMessages(['lines' => "البند {$item->code} أمر تغيير بالحذف ولا يُفوتر."]);
                }
                $billed = (float) $item->billed_quantity;
                if ($billed + $quantity > (float) $item->quantity + self::TOLERANCE || $billed + $quantity < -self::TOLERANCE) {
                    throw ValidationException::withMessages(['lines' => "الكمية التراكمية للبند {$item->code} تتجاوز كمية العقد ({$item->quantity})."]);
                }

                $amount = round($quantity * (float) $item->unit_price, 2);
                $billing->items()->create([
                    'boq_item_id' => $item->id,
                    'previous_quantity' => $billed,
                    'current_quantity' => $quantity,
                    'unit_price' => (float) $item->unit_price,
                    'amount' => $amount,
                ]);
                $current += $amount;
            }

            if ($current <= 0) {
                throw ValidationException::withMessages(['lines' => 'قيمة الأعمال الحالية يجب أن تكون أكبر من صفر.']);
            }

            $previous = (float) ProgressBilling::where('project_id', $project->id)->where('status', 'approved')->sum('current_value');
            $advanceOpen = max(0, (float) $project->advance_received - (float) $project->advance_recovered);
            $recovery = round(min($advanceOpen, $current * (float) $project->advance_percent / 100), 2);
            $retention = round($current * (float) $project->retention_percent / 100, 2);
            $taxable = round($current - $recovery, 2);
            $vat = round($taxable * TaxProfile::rate() / 100, 2);

            $billing->update([
                'previous_value' => round($previous, 2),
                'current_value' => round($current, 2),
                'cumulative_value' => round($previous + $current, 2),
                'advance_recovery' => $recovery,
                'retention' => $retention,
                'taxable_amount' => $taxable,
                'vat_amount' => $vat,
                'net_payable' => round($taxable + $vat - $retention, 2),
            ]);

            return $billing->fresh('items');
        });
    }

    /**
     * Approve a billing: certified quantities, ZATCA tax invoice for the
     * taxable value (work less advance recovery) and the contract entry.
     */
    public function approveBilling(ProgressBilling $billing): Invoice
    {
        return DB::transaction(function () use ($billing) {
            $billing = ProgressBilling::with('items.boqItem')->whereKey($billing->id)->lockForUpdate()->firstOrFail();
            if ($billing->status !== 'draft') {
                throw ValidationException::withMessages(['billing' => 'المستخلص معتمد مسبقاً.']);
            }
            $project = Project::whereKey($billing->project_id)->lockForUpdate()->firstOrFail();

            // Advance recovery is spread over the lines as a deduction.
            $current = (float) $billing->current_value;
            $remainingRecovery = (float) $billing->advance_recovery;
            $lines = [];
            foreach ($billing->items->values() as $i => $item) {
                $share = $i === $billing->items->count() - 1 ? $remainingRecovery : round((float) $billing->advance_recovery * (float) $item->amount / $current, 2);
                $remainingRecovery = round($remainingRecovery - $share, 2);
                $lines[] = [
                    'item_name' => "{$item->boqItem->code} - ".mb_substr($item->boqItem->description, 0, 200),
                    'quantity' => (float) $item->current_quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount' => $share,
                    'tax_rate' => TaxProfile::rate(),
                ];
                ProjectBoqItem::whereKey($item->boq_item_id)->increment('billed_quantity', (float) $item->current_quantity);
            }

            $invoice = $this->invoices->create([
                'customer_id' => $project->customer_id,
                'branch_id' => $project->branch_id,
                'invoice_date' => $billing->billing_date->toDateString(),
                'supply_date' => ($billing->period_to ?? $billing->billing_date)->toDateString(),
                'payment_method' => 'credit',
                'payment_status' => 'unpaid',
                'reference_number' => $billing->number,
                'notes' => "مستخلص رقم {$billing->sequence} - مشروع {$project->code} {$project->name}",
            ], $lines, ['issue_stock' => false, 'post' => false, 'check_credit' => false]);

            // Retention is held back from what is collectable now.
            $retention = (float) $billing->retention;
            $due = round((float) $invoice->total - $retention, 2);
            $invoice->update(['remaining_amount' => $due, 'payment_status' => $due <= 0 ? 'paid' : 'unpaid']);
            Customer::whereKey($project->customer_id)->decrement('current_balance', $retention);

            $cc = $project->cost_center_id;
            $party = ['party_type' => 'customer', 'party_id' => $project->customer_id];
            $this->posting->post([
                'date' => $invoice->invoice_date,
                'branch_id' => $project->branch_id,
                'reference' => $billing->number,
                'description' => "مستخلص {$billing->number} - {$project->name}",
                'source' => $billing,
                'lines' => array_values(array_filter([
                    $due > 0 ? ['role' => 'receivables', 'debit' => $due] + $party : null,
                    (float) $billing->advance_recovery > 0 ? ['role' => 'customer_advances', 'debit' => (float) $billing->advance_recovery, 'cost_center_id' => $cc] + $party : null,
                    $retention > 0 ? ['role' => 'retention_receivable', 'debit' => $retention, 'cost_center_id' => $cc] + $party : null,
                    ['role' => 'contract_revenue', 'credit' => $current, 'cost_center_id' => $cc],
                    (float) $invoice->tax_amount > 0 ? ['role' => 'vat_output', 'credit' => (float) $invoice->tax_amount] : null,
                ])),
            ]);

            $project->update([
                'advance_recovered' => round((float) $project->advance_recovered + (float) $billing->advance_recovery, 2),
                'retention_held' => round((float) $project->retention_held + $retention, 2),
            ]);
            $billing->update([
                'status' => 'approved',
                'invoice_id' => $invoice->id,
                'vat_amount' => (float) $invoice->tax_amount,
                'net_payable' => $due,
                'approved_by' => auth()->id(),
            ]);

            return $invoice;
        });
    }

    public function releaseRetention(Project $project, float $amount, string $date): void
    {
        DB::transaction(function () use ($project, $amount, $date) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $open = (float) $project->retention_held - (float) $project->retention_released;
            if ($amount <= 0 || $amount > $open + 0.005) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يتجاوز المحتجزات القائمة ('.number_format($open, 2).').']);
            }

            $this->posting->post([
                'date' => $date,
                'reference' => $project->code,
                'description' => "الإفراج عن محتجزات مشروع {$project->code}",
                'source' => $project,
                'lines' => [
                    ['role' => 'receivables', 'debit' => $amount, 'party_type' => 'customer', 'party_id' => $project->customer_id],
                    ['role' => 'retention_receivable', 'credit' => $amount, 'party_type' => 'customer', 'party_id' => $project->customer_id, 'cost_center_id' => $project->cost_center_id],
                ],
            ]);

            Customer::whereKey($project->customer_id)->increment('current_balance', $amount);
            $project->update(['retention_released' => round((float) $project->retention_released + $amount, 2)]);
        });
    }

    // ----- Subcontractors -----

    public function createSubcontract(Project $project, array $data): Subcontract
    {
        return Subcontract::create([
            'project_id' => $project->id,
            'supplier_id' => $data['supplier_id'],
            'number' => $this->numbers->next('subcontract'),
            'scope' => $data['scope'],
            'contract_value' => round((float) $data['contract_value'], 2),
            'retention_percent' => (float) ($data['retention_percent'] ?? 0),
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
        ]);
    }

    public function paySubcontractAdvance(Subcontract $subcontract, array $data): void
    {
        DB::transaction(function () use ($subcontract, $data) {
            $subcontract = Subcontract::with('project')->whereKey($subcontract->id)->lockForUpdate()->firstOrFail();
            $amount = round((float) $data['amount'], 2);

            $this->posting->post([
                'date' => $data['date'],
                'reference' => $subcontract->number,
                'description' => "دفعة مقدمة لمقاول الباطن - {$subcontract->number}",
                'source' => $subcontract,
                'lines' => [
                    ['role' => 'supplier_advances', 'debit' => $amount, 'party_type' => 'supplier', 'party_id' => $subcontract->supplier_id, 'cost_center_id' => $subcontract->project->cost_center_id],
                    ['account_id' => $this->poster->cashAccount($data['bank_account_id'] ?? null, $data['payment_method'] ?? 'bank_transfer'), 'credit' => $amount],
                ],
            ]);

            $subcontract->update(['advance_paid' => round((float) $subcontract->advance_paid + $amount, 2)]);
        });
    }

    /**
     * Subcontractor payment certificate from the cumulative value of work done.
     */
    public function certify(Subcontract $subcontract, array $data): SubcontractCertificate
    {
        return DB::transaction(function () use ($subcontract, $data) {
            $subcontract = Subcontract::with('project')->whereKey($subcontract->id)->lockForUpdate()->firstOrFail();
            $cumulative = round((float) $data['cumulative_value'], 2);
            $current = round($cumulative - (float) $subcontract->certified_to_date, 2);

            if ($current <= 0) {
                throw ValidationException::withMessages(['cumulative_value' => 'القيمة التراكمية يجب أن تتجاوز ما سبق اعتماده ('.number_format((float) $subcontract->certified_to_date, 2).').']);
            }
            if ($cumulative > (float) $subcontract->contract_value + 0.005 && ! ($data['allow_overrun'] ?? false)) {
                throw ValidationException::withMessages(['cumulative_value' => 'القيمة التراكمية تتجاوز قيمة عقد الباطن.']);
            }

            $advanceShare = (float) $subcontract->contract_value > 0 ? (float) $subcontract->advance_paid / (float) $subcontract->contract_value : 0;
            $recovery = round(min((float) $subcontract->advance_paid - (float) $subcontract->advance_recovered, $current * $advanceShare), 2);
            $retention = round($current * (float) $subcontract->retention_percent / 100, 2);
            $vatRate = (float) ($data['vat_rate'] ?? TaxProfile::rate());
            $vat = round($current * $vatRate / 100, 2);
            $net = round($current + $vat - $recovery - $retention, 2);

            $count = SubcontractCertificate::where('subcontract_id', $subcontract->id)->count();
            $certificate = SubcontractCertificate::create([
                'subcontract_id' => $subcontract->id,
                'number' => sprintf('%s-C%02d', $subcontract->number, $count + 1),
                'date' => $data['date'],
                'cumulative_value' => $cumulative,
                'current_value' => $current,
                'advance_recovery' => $recovery,
                'retention' => $retention,
                'vat_rate' => $vatRate,
                'vat_amount' => $vat,
                'net_payable' => $net,
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $cc = $subcontract->project->cost_center_id;
            $party = ['party_type' => 'supplier', 'party_id' => $subcontract->supplier_id];
            $this->posting->post([
                'date' => $data['date'],
                'reference' => $certificate->number,
                'description' => "مستخلص مقاول باطن {$certificate->number} - مشروع {$subcontract->project->code}",
                'source' => $certificate,
                'lines' => array_values(array_filter([
                    ['role' => 'subcontractor_costs', 'debit' => $current, 'cost_center_id' => $cc],
                    $vat > 0 ? ['role' => 'vat_input', 'debit' => $vat] : null,
                    $net > 0 ? ['role' => 'payables', 'credit' => $net] + $party : null,
                    $recovery > 0 ? ['role' => 'supplier_advances', 'credit' => $recovery, 'cost_center_id' => $cc] + $party : null,
                    $retention > 0 ? ['role' => 'retention_payable', 'credit' => $retention, 'cost_center_id' => $cc] + $party : null,
                ])),
            ]);

            Supplier::whereKey($subcontract->supplier_id)->increment('current_balance', $net);
            $subcontract->update([
                'certified_to_date' => $cumulative,
                'advance_recovered' => round((float) $subcontract->advance_recovered + $recovery, 2),
                'retention_held' => round((float) $subcontract->retention_held + $retention, 2),
            ]);

            ProjectCost::create([
                'project_id' => $subcontract->project_id,
                'category' => 'subcontract',
                'source' => 'subcontract',
                'reference' => $certificate->number,
                'date' => $data['date'],
                'description' => "مستخلص مقاول باطن {$certificate->number}",
                'amount' => $current,
                'supplier_id' => $subcontract->supplier_id,
                'created_by' => auth()->id(),
            ]);

            return $certificate;
        });
    }

    // ----- Project costs -----

    /**
     * Issue materials from a warehouse to the project (or return them when
     * $return is true) at actual stock cost.
     *
     * @param  array  $lines  [product_id, quantity, boq_item_id?]
     */
    public function moveMaterials(Project $project, array $data, array $lines, bool $return = false): float
    {
        return DB::transaction(function () use ($project, $data, $lines, $return) {
            $warehouseId = $this->documentStock->warehouse($data['warehouse_id'] ?? $project->warehouse_id);
            $total = 0.0;

            foreach ($lines as $line) {
                $quantity = (float) $line['quantity'];
                if ($quantity <= 0) {
                    continue;
                }
                $product = Product::findOrFail($line['product_id']);
                $cost = ProjectCost::create([
                    'project_id' => $project->id,
                    'boq_item_id' => $line['boq_item_id'] ?? null,
                    'category' => 'material',
                    'source' => $return ? 'material_return' : 'material_issue',
                    'reference' => $data['reference'] ?? null,
                    'date' => $data['date'],
                    'description' => ($return ? 'مرتجع مواد من المشروع: ' : 'صرف مواد للمشروع: ').$product->name_ar,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $quantity,
                    'created_by' => auth()->id(),
                ]);

                if ($return) {
                    $unitCost = $this->issuedUnitCost($project, $product);
                    $this->stock->receive(['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'quantity' => $quantity, 'unit_cost' => $unitCost,
                        'type' => 'project_return', 'date' => $data['date'], 'source' => $cost, 'reference' => $project->code]);
                    $value = round($unitCost * $quantity, 2);
                } else {
                    $value = $this->stock->issue(['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'quantity' => $quantity,
                        'type' => 'project_issue', 'date' => $data['date'], 'source' => $cost, 'reference' => $project->code,
                        'serials' => $line['serials'] ?? [], 'batch_id' => $line['batch_id'] ?? null]);
                }

                $cost->update(['unit_cost' => round($value / $quantity, 4), 'amount' => $return ? -$value : $value]);
                $total += $value;
            }

            if ($total > 0) {
                $cc = $project->cost_center_id;
                $this->posting->post([
                    'date' => $data['date'],
                    'reference' => $project->code,
                    'description' => ($return ? 'مرتجع مواد من مشروع ' : 'صرف مواد لمشروع ').$project->code,
                    'source' => $project,
                    'lines' => $return
                        ? [['role' => 'inventory', 'debit' => round($total, 2)], ['role' => 'project_costs', 'credit' => round($total, 2), 'cost_center_id' => $cc]]
                        : [['role' => 'project_costs', 'debit' => round($total, 2), 'cost_center_id' => $cc], ['role' => 'inventory', 'credit' => round($total, 2)]],
                ]);
            }

            return round($total, 2);
        });
    }

    /**
     * Labour, equipment or other direct cost charged to the project.
     */
    public function recordCost(Project $project, array $data): ProjectCost
    {
        return DB::transaction(function () use ($project, $data) {
            $amount = round((float) $data['amount'], 2);
            $vat = round((float) ($data['vat_amount'] ?? 0), 2);
            $paidFrom = $data['paid_from'] ?? 'accrued';

            $cost = ProjectCost::create([
                'project_id' => $project->id,
                'boq_item_id' => $data['boq_item_id'] ?? null,
                'category' => $data['category'],
                'source' => 'direct',
                'reference' => $data['reference'] ?? null,
                'date' => $data['date'],
                'description' => $data['description'],
                'fixed_asset_id' => $data['fixed_asset_id'] ?? null,
                'quantity' => (float) ($data['quantity'] ?? 0),
                'unit_cost' => (float) ($data['unit_cost'] ?? 0),
                'amount' => $amount,
                'paid_from' => $paidFrom,
                'supplier_id' => $data['supplier_id'] ?? null,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $credit = match ($paidFrom) {
                'payables' => ['role' => 'payables', 'party_type' => 'supplier', 'party_id' => $data['supplier_id']],
                'accrued' => ['role' => 'accrued_expenses'],
                default => ['account_id' => $this->poster->cashAccount($data['bank_account_id'] ?? null, $paidFrom)],
            };

            $this->posting->post([
                'date' => $data['date'],
                'reference' => $data['reference'] ?? $project->code,
                'description' => "تكلفة مشروع {$project->code}: {$data['description']}",
                'source' => $cost,
                'lines' => array_values(array_filter([
                    ['role' => 'project_costs', 'debit' => $amount, 'cost_center_id' => $project->cost_center_id],
                    $vat > 0 ? ['role' => 'vat_input', 'debit' => $vat] : null,
                    $credit + ['credit' => round($amount + $vat, 2)],
                ])),
            ]);

            if ($paidFrom === 'payables') {
                Supplier::whereKey($data['supplier_id'])->increment('current_balance', round($amount + $vat, 2));
            }

            return $cost;
        });
    }

    /** Average cost at which this product was issued to the project. */
    protected function issuedUnitCost(Project $project, Product $product): float
    {
        $row = ProjectCost::where('project_id', $project->id)->where('product_id', $product->id)->where('source', 'material_issue')
            ->selectRaw('SUM(quantity) q, SUM(amount) a')->first();

        return $row && (float) $row->q > 0 ? round((float) $row->a / (float) $row->q, 4) : (float) $product->average_cost;
    }
}
