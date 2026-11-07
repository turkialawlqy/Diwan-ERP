<?php

namespace App\Services\Accounting;

use App\Models\CreditNote;
use App\Models\CustomerPayment;
use App\Models\DebitNote;
use App\Models\DeliveryNote;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Payroll;
use App\Models\PosSession;
use App\Models\Project;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\Revenue;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\Route;

/**
 * The document a journal entry was posted from (journal_entries.source_type / source_id), as a
 * label and, when the document has a screen, a link — so the ledger and the entry open it.
 */
class SourceDocument
{
    /** model class => [label, route name] (a null route means the document has no screen of its own) */
    protected const KNOWN = [
        Invoice::class => ['فاتورة مبيعات', 'sales.invoices.show'],
        PurchaseInvoice::class => ['فاتورة مشتريات', 'purchases.invoices.show'],
        CreditNote::class => ['إشعار دائن / مرتجع مبيعات', 'sales.returns.show'],
        PurchaseReturn::class => ['مرتجع مشتريات', 'purchases.returns.show'],
        DeliveryNote::class => ['إذن تسليم', 'sales.deliveries.show'],
        GoodsReceipt::class => ['استلام بضاعة', 'purchases.receipts.show'],
        Payroll::class => ['مسير رواتب', 'hr.payroll.show'],
        StockTransfer::class => ['تحويل مخزني', 'inventory.stock-transfers.show'],
        FixedAsset::class => ['أصل ثابت', 'accounting.fixed-assets.show'],
        Project::class => ['مشروع', 'projects.show'],
        Expense::class => ['سند مصروف', 'finance.expenses.edit'],
        Revenue::class => ['سند إيراد', 'finance.revenues.edit'],
        CustomerPayment::class => ['سند قبض', null],
        SupplierPayment::class => ['سند صرف', null],
        StockCount::class => ['جرد مخزون', null],
        DebitNote::class => ['إشعار مدين', null],
        PosSession::class => ['وردية كاشير', null],
    ];

    /** @return array{label: string, url: ?string}|null */
    public function describe(?string $type, $id): ?array
    {
        if (! $type) {
            return null;
        }
        [$label, $route] = self::KNOWN[$type] ?? [class_basename($type), null];

        return [
            'label' => $label,
            'url' => $route && $id && Route::has($route) ? route($route, $id) : null,
        ];
    }

    /** Labels of the source types, for filters: class => label. */
    public function labels(): array
    {
        return array_map(fn ($k) => $k[0], self::KNOWN);
    }
}
