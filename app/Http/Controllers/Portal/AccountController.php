<?php

namespace App\Http\Controllers\Portal;

use App\Services\Documents\DocumentSharing;
use App\Services\Sales\CustomerStatement;
use Illuminate\Http\Request;

/** Dashboard, invoices, statement and receipts: display only (no online payment). */
class AccountController extends PortalController
{
    public function home(Request $request)
    {
        $scope = $this->scope();
        $open = $scope->invoices()->where('remaining_amount', '>', 0);
        $support = $this->has($request, 'support');
        $contracts = $this->has($request, 'contracts');

        return $this->page($request, 'Portal/Home', [
            'balance' => (float) $scope->customer()->current_balance,
            'openInvoices' => ['count' => (clone $open)->count(), 'remaining' => (float) (clone $open)->sum('remaining_amount'), 'overdue' => (clone $open)->whereDate('due_date', '<', today())->count()],
            'openTickets' => $support ? $scope->tickets()->whereIn('status', ['open', 'pending'])->count() : null,
            'toAccept' => $contracts ? $this->awaiting($scope)->count() : null,
            'recentInvoices' => $scope->invoices()->latest('invoice_date')->limit(5)->get($this->invoiceColumns()),
        ]);
    }

    public function invoices(Request $request)
    {
        $status = $request->query('status', 'all');
        $invoices = $this->scope()->invoices()
            ->when($status === 'open', fn ($q) => $q->where('remaining_amount', '>', 0))
            ->when($status === 'paid', fn ($q) => $q->where('remaining_amount', '<=', 0))
            ->latest('invoice_date')->latest('id')->paginate(20, $this->invoiceColumns())->withQueryString();

        return $this->page($request, 'Portal/Invoices', ['invoices' => $invoices, 'status' => $status]);
    }

    public function invoice(Request $request, int $company, int $invoice)
    {
        $invoice = $this->scope()->invoices()->with('items:id,invoice_id,item_name,quantity,unit_price,discount,tax_rate,subtotal,tax_amount,total')->findOrFail($invoice);

        return $this->page($request, 'Portal/InvoiceShow', [
            'invoice' => $invoice->only(array_merge($this->invoiceColumns(), ['subtotal', 'tax_amount', 'discount_amount', 'payment_method'])) + ['items' => $invoice->items],
            'pdfUrl' => app(DocumentSharing::class)->pdfLink($invoice),
        ]);
    }

    public function statement(Request $request)
    {
        $customer = $this->scope()->customer();
        $statement = app(CustomerStatement::class)->build($customer);

        return $this->page($request, 'Portal/Statement', [
            'statement' => [
                'opening' => $statement['opening'], 'closing' => $statement['closing'],
                'transactions' => $statement['transactions']->map(fn ($t) => ['date' => $t['date'], 'type' => $t['type_ar'], 'reference' => $t['reference'], 'debit' => $t['debit'], 'credit' => $t['credit'], 'balance' => $t['balance'] ?? null])->values(),
            ],
            'pdfUrl' => app(DocumentSharing::class)->portalLink($customer, true),
        ]);
    }

    public function payments(Request $request)
    {
        $payments = $this->scope()->payments()->latest('payment_date')->latest('id')->paginate(20, ['id', 'payment_number', 'payment_date', 'amount', 'payment_method', 'currency_code'])->withQueryString();

        return $this->page($request, 'Portal/Payments', ['payments' => $payments]);
    }

    /** Contracts shared with the customer that still wait for his acceptance of their current version. */
    protected function awaiting($scope)
    {
        return $scope->contracts()->where('requires_acceptance', true)->whereIn('status', ['approved', 'active'])
            ->whereDoesntHave('acceptances', fn ($q) => $q->whereColumn('contract_acceptances.version', 'contracts.version'));
    }

    protected function invoiceColumns(): array
    {
        return ['id', 'invoice_number', 'invoice_date', 'due_date', 'total', 'paid_amount', 'remaining_amount', 'payment_status', 'currency_code'];
    }
}
