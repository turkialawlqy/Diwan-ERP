<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\CreditNote;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\PurchaseInvoice;
use App\Models\Revenue;
use App\Models\SupplierPayment;
use App\Services\Inventory\CostingService;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal templates for every operational document. Each method builds the
 * double entry for one document and posts it through PostingService inside
 * the caller's transaction; cancelling a document calls reverse().
 *
 * Sub-ledger: receivable / payable lines carry party_type + party_id so
 * customer and supplier statements and aging can be read from the ledger.
 */
class DocumentPoster
{
    public function __construct(
        protected PostingService $posting,
        protected AccountMap $accounts,
        protected CostingService $costing,
    ) {}

    /**
     * Sales tax invoice (also simplified / POS invoices).
     *   Dr Cash/Bank (paid part)  Dr Receivables (unpaid part)
     *   Cr Sales (net of discount)  Cr VAT output
     *   Dr COGS  Cr Inventory (stock items)
     */
    public function salesInvoice(Invoice $invoice, ?int $warehouseId = null): JournalEntry
    {
        $invoice->loadMissing('items', 'customer');

        $total = (float) $invoice->total;
        $tax = (float) $invoice->tax_amount;
        $paid = min((float) $invoice->paid_amount, $total);
        $customer = $invoice->customer?->name ?? 'عميل نقدي';

        $lines = [];

        if ($paid > 0) {
            $lines[] = ['account_id' => $this->cashAccount($invoice->bank_account_id, $invoice->payment_method), 'debit' => $paid, 'description' => "تحصيل فاتورة {$invoice->invoice_number}"];
        }

        if ($total - $paid > 0) {
            $lines[] = ['role' => 'receivables', 'debit' => $total - $paid, 'description' => "ذمم {$customer} - فاتورة {$invoice->invoice_number}", 'party_type' => 'customer', 'party_id' => $invoice->customer_id];
        }

        $lines[] = ['role' => 'sales_revenue', 'credit' => $total - $tax, 'description' => "مبيعات فاتورة {$invoice->invoice_number}"];

        if ($tax > 0) {
            $lines[] = ['role' => 'vat_output', 'credit' => $tax, 'description' => "ضريبة مخرجات فاتورة {$invoice->invoice_number}"];
        }

        array_push($lines, ...$this->costOfSalesLines($invoice->items, $warehouseId, "تكلفة فاتورة {$invoice->invoice_number}"));

        return $this->posting->post([
            'date' => $invoice->invoice_date,
            'branch_id' => $invoice->branch_id,
            'reference' => $invoice->invoice_number,
            'description' => "فاتورة مبيعات رقم {$invoice->invoice_number} - {$customer}",
            'source' => $invoice,
            'lines' => $lines,
        ]);
    }

    /**
     * Sales return / credit note (amount based).
     *   Dr Sales returns  Dr VAT output   Cr Receivables (or Cash when refunded)
     */
    public function creditNote(CreditNote $note, array $returnedItems = [], ?int $warehouseId = null): ?JournalEntry
    {
        if (! in_array($note->status, ['approved', 'refunded'], true)) {
            return null;
        }

        $total = (float) $note->total;
        $tax = (float) $note->tax_amount;
        $refunded = $note->status === 'refunded' && $note->refund_method && $note->refund_method !== 'credit';

        $lines = [
            ['role' => 'sales_returns', 'debit' => $total - $tax, 'description' => "مردودات مبيعات {$note->credit_note_number}"],
        ];

        if ($tax > 0) {
            $lines[] = ['role' => 'vat_output', 'debit' => $tax, 'description' => "عكس ضريبة مخرجات {$note->credit_note_number}"];
        }

        $lines[] = $refunded
            ? ['account_id' => $this->cashAccount($note->bank_account_id, $note->refund_method), 'credit' => $total, 'description' => "رد مبلغ مرتجع {$note->credit_note_number}"]
            : ['role' => 'receivables', 'credit' => $total, 'description' => "تخفيض ذمة العميل - {$note->credit_note_number}", 'party_type' => 'customer', 'party_id' => $note->customer_id];

        // Returned stock goes back to inventory at cost.
        foreach ($returnedItems as $item) {
            $cost = round($this->costing->unitCost($item['product_id'] ?? null, $warehouseId) * (float) $item['quantity'], 2);
            if ($cost > 0) {
                $lines[] = ['role' => 'inventory', 'debit' => $cost, 'description' => 'إرجاع بضاعة للمخزون'];
                $lines[] = ['role' => 'cogs', 'credit' => $cost, 'description' => 'عكس تكلفة مبيعات مرتجعة'];
            }
        }

        return $this->posting->post([
            'date' => $note->credit_note_date,
            'reference' => $note->credit_note_number,
            'description' => "إشعار دائن / مرتجع مبيعات {$note->credit_note_number}",
            'source' => $note,
            'lines' => $lines,
        ]);
    }

    /**
     * Customer receipt.   Dr Cash/Bank   Cr Receivables
     */
    public function customerPayment(CustomerPayment $payment): JournalEntry
    {
        return $this->posting->post([
            'date' => $payment->payment_date,
            'reference' => $payment->payment_number,
            'description' => "سند قبض رقم {$payment->payment_number}",
            'source' => $payment,
            'lines' => [
                ['account_id' => $this->cashAccount($payment->bank_account_id, $payment->payment_method), 'debit' => (float) $payment->amount, 'description' => "سند قبض {$payment->payment_number}"],
                ['role' => 'receivables', 'credit' => (float) $payment->amount, 'description' => "تحصيل من العميل - {$payment->payment_number}", 'party_type' => 'customer', 'party_id' => $payment->customer_id],
            ],
        ]);
    }

    /**
     * Supplier bill.
     *   Dr Inventory (stock items) / Purchases (non stock)   Dr VAT input
     *   Cr Payables (unpaid part)   Cr Cash/Bank (paid part)
     */
    public function purchaseInvoice(PurchaseInvoice $invoice): JournalEntry
    {
        $invoice->loadMissing('items.product', 'supplier');

        $tax = (float) $invoice->tax_amount;
        $total = (float) $invoice->total;
        $net = $total - $tax;
        $paid = min((float) $invoice->paid_amount, $total);
        $supplier = $invoice->supplier?->name ?? 'مورد';

        // Split the net amount between stock and non-stock lines.
        $itemsNet = 0.0;
        $stockNet = 0.0;
        foreach ($invoice->items as $item) {
            $lineNet = (float) $item->quantity * (float) $item->unit_price - (float) ($item->discount ?? 0);
            $itemsNet += $lineNet;
            if ($item->product && $item->product->track_inventory) {
                $stockNet += $lineNet;
            }
        }
        $stockShare = $itemsNet > 0 ? round($net * ($stockNet / $itemsNet), 2) : 0.0;

        $lines = [];
        if ($stockShare > 0) {
            $lines[] = ['role' => 'inventory', 'debit' => $stockShare, 'description' => "بضاعة واردة - فاتورة {$invoice->invoice_number}"];
        }
        if ($net - $stockShare > 0) {
            $lines[] = ['role' => 'purchases_cogs', 'debit' => $net - $stockShare, 'description' => "مشتريات - فاتورة {$invoice->invoice_number}"];
        }
        if ($tax > 0) {
            $lines[] = ['role' => 'vat_input', 'debit' => $tax, 'description' => "ضريبة مدخلات - فاتورة {$invoice->invoice_number}"];
        }
        if ($total - $paid > 0) {
            $lines[] = ['role' => 'payables', 'credit' => $total - $paid, 'description' => "مستحق للمورد {$supplier}", 'party_type' => 'supplier', 'party_id' => $invoice->supplier_id];
        }
        if ($paid > 0) {
            $lines[] = ['account_id' => $this->cashAccount($invoice->bank_account_id, $invoice->payment_method), 'credit' => $paid, 'description' => "سداد فاتورة شراء {$invoice->invoice_number}"];
        }

        return $this->posting->post([
            'date' => $invoice->invoice_date,
            'branch_id' => $invoice->branch_id,
            'reference' => $invoice->supplier_invoice_number ?: $invoice->invoice_number,
            'description' => "فاتورة مشتريات رقم {$invoice->invoice_number} - {$supplier}",
            'source' => $invoice,
            'lines' => $lines,
        ]);
    }

    /**
     * Supplier payment.   Dr Payables   Cr Cash/Bank
     */
    public function supplierPayment(SupplierPayment $payment): JournalEntry
    {
        return $this->posting->post([
            'date' => $payment->payment_date,
            'reference' => $payment->payment_number,
            'description' => "سند صرف للمورد رقم {$payment->payment_number}",
            'source' => $payment,
            'lines' => [
                ['role' => 'payables', 'debit' => (float) $payment->amount, 'description' => "سداد للمورد - {$payment->payment_number}", 'party_type' => 'supplier', 'party_id' => $payment->supplier_id],
                ['account_id' => $this->cashAccount($payment->bank_account_id, $payment->payment_method), 'credit' => (float) $payment->amount, 'description' => "سند صرف {$payment->payment_number}"],
            ],
        ]);
    }

    /**
     * Expense voucher.   Dr Expense   Dr VAT input   Cr Cash/Bank
     */
    public function expense(Expense $expense): JournalEntry
    {
        $amount = (float) $expense->amount;
        $tax = (float) $expense->tax_amount;

        return $this->posting->post([
            'date' => $expense->expense_date,
            'reference' => $expense->expense_number,
            'description' => "مصروف {$expense->expense_number} - {$expense->title}",
            'source' => $expense,
            'lines' => array_filter([
                $expense->account_id
                    ? ['account_id' => $expense->account_id, 'debit' => $amount, 'description' => $expense->title, 'cost_center_id' => $expense->cost_center_id]
                    : ['role' => 'general_expense', 'debit' => $amount, 'description' => $expense->title, 'cost_center_id' => $expense->cost_center_id],
                $tax > 0 ? ['role' => 'vat_input', 'debit' => $tax, 'description' => "ضريبة مدخلات - {$expense->expense_number}"] : null,
                ['account_id' => $this->cashAccount($expense->bank_account_id, $expense->payment_method), 'credit' => $amount + $tax, 'description' => "صرف {$expense->expense_number}"],
            ]),
        ]);
    }

    /**
     * Other revenue voucher.   Dr Cash/Bank   Cr Revenue   Cr VAT output
     */
    public function revenue(Revenue $revenue): JournalEntry
    {
        $amount = (float) $revenue->amount;
        $tax = (float) $revenue->tax_amount;

        return $this->posting->post([
            'date' => $revenue->revenue_date,
            'reference' => $revenue->revenue_number,
            'description' => "إيراد {$revenue->revenue_number} - {$revenue->title}",
            'source' => $revenue,
            'lines' => array_filter([
                ['account_id' => $this->cashAccount($revenue->bank_account_id, $revenue->payment_method), 'debit' => $amount + $tax, 'description' => "قبض {$revenue->revenue_number}"],
                $revenue->account_id
                    ? ['account_id' => $revenue->account_id, 'credit' => $amount, 'description' => $revenue->title, 'cost_center_id' => $revenue->cost_center_id]
                    : ['role' => 'other_revenue', 'credit' => $amount, 'description' => $revenue->title, 'cost_center_id' => $revenue->cost_center_id],
                $tax > 0 ? ['role' => 'vat_output', 'credit' => $tax, 'description' => "ضريبة مخرجات - {$revenue->revenue_number}"] : null,
            ]),
        ]);
    }

    /**
     * Transfer between cash boxes / banks.   Dr destination   Cr source
     */
    public function transfer(BankAccount $from, BankAccount $to, float $amount, $date, string $reference, string $description, ?float $fees = null): JournalEntry
    {
        $lines = [
            ['account_id' => $this->boxAccount($to), 'debit' => $amount, 'description' => "تحويل وارد من {$from->name}"],
            ['account_id' => $this->boxAccount($from), 'credit' => $amount + (float) $fees, 'description' => "تحويل صادر إلى {$to->name}"],
        ];

        if ($fees) {
            $lines[] = ['role' => 'bank_charges', 'debit' => $fees, 'description' => 'عمولة تحويل'];
        }

        return $this->posting->post([
            'date' => $date,
            'reference' => $reference,
            'description' => $description,
            'source_type' => 'transfer',
            'lines' => $lines,
        ]);
    }

    /**
     * Payroll accrual.   Dr Salaries expense   Cr Salaries payable (net)  Cr deductions
     */
    public function payroll(Payroll $payroll): JournalEntry
    {
        $gross = (float) $payroll->total_basic + (float) $payroll->total_allowances;
        $deductions = (float) $payroll->total_deductions;

        return $this->posting->post([
            'date' => $payroll->payment_date ?? now()->setDate((int) $payroll->year, (int) $payroll->month, 1)->endOfMonth(),
            'reference' => $payroll->payroll_number,
            'description' => "استحقاق رواتب شهر {$payroll->month}/{$payroll->year}",
            'source' => $payroll,
            'lines' => array_filter([
                ['role' => 'salaries_expense', 'debit' => $gross, 'description' => 'إجمالي الرواتب والبدلات'],
                ['role' => 'salaries_payable', 'credit' => $gross - $deductions, 'description' => 'صافي الرواتب المستحقة'],
                $deductions > 0 ? ['role' => 'employee_advances', 'credit' => $deductions, 'description' => 'استقطاعات وسلف'] : null,
            ]),
        ]);
    }

    /**
     * Stock adjustment / count difference valued at cost.
     *   Increase: Dr Inventory  Cr Inventory adjustments  (decrease: the opposite)
     */
    public function stockAdjustment($source, float $value, $date, string $description): ?JournalEntry
    {
        $value = round($value, 2);
        if ($value == 0.0) {
            return null;
        }

        return $this->posting->post([
            'date' => $date,
            'description' => $description,
            'source' => $source instanceof Model ? $source : null,
            'source_type' => is_string($source) ? $source : null,
            'lines' => $value > 0
                ? [['role' => 'inventory', 'debit' => $value], ['role' => 'inventory_adjustment', 'credit' => $value]]
                : [['role' => 'inventory_adjustment', 'debit' => abs($value)], ['role' => 'inventory', 'credit' => abs($value)]],
        ]);
    }

    /**
     * Opening balance of a cash box, customer or supplier against the
     * "opening balance equity" account. A positive amount is a debit for the
     * debit side account (cash / receivable) and a credit for payables.
     */
    public function openingBalance(Model $source, array $line, float $amount, $date, string $description): ?JournalEntry
    {
        $amount = round($amount, 2);
        if ($amount == 0.0) {
            return null;
        }

        $debitSide = ($line['side'] ?? 'debit') === 'debit';
        $isDebit = $debitSide ? $amount > 0 : $amount < 0;
        $value = abs($amount);
        unset($line['side']);

        return $this->posting->post([
            'date' => $date,
            'description' => $description,
            'entry_type' => 'opening',
            'source' => $source,
            'lines' => [
                array_merge($line, ['debit' => $isDebit ? $value : 0, 'credit' => $isDebit ? 0 : $value, 'description' => $description]),
                ['role' => 'opening_balance_equity', 'debit' => $isDebit ? 0 : $value, 'credit' => $isDebit ? $value : 0, 'description' => $description],
            ],
        ]);
    }

    /**
     * Cancel a document: reverse its journal entries.
     */
    public function reverse($source, ?string $description = null): void
    {
        $this->posting->reverseSource($source, $description);
    }

    /**
     * Ledger account receiving / paying money for a document.
     */
    public function cashAccount(?int $bankAccountId, ?string $paymentMethod = null): int
    {
        if ($bankAccountId) {
            $box = BankAccount::find($bankAccountId);
            if ($box) {
                return $this->boxAccount($box);
            }
        }

        return $this->accounts->id(in_array($paymentMethod, ['cash', null, ''], true) ? 'default_cash' : 'default_bank');
    }

    public function boxAccount(BankAccount $box): int
    {
        return $box->account_id ?: $this->accounts->id($box->type === 'cash' ? 'default_cash' : 'default_bank');
    }

    protected function costOfSalesLines(iterable $items, ?int $warehouseId, string $description): array
    {
        $cost = 0.0;

        foreach ($items as $item) {
            $cost += $this->costing->unitCost($item->product_id, $warehouseId) * (float) $item->quantity;
        }

        $cost = round($cost, 2);

        return $cost > 0
            ? [['role' => 'cogs', 'debit' => $cost, 'description' => $description], ['role' => 'inventory', 'credit' => $cost, 'description' => $description]]
            : [];
    }
}
