<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting as A;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\ApprovalRuleController;
use App\Http\Controllers\AssemblyController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\Crm;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataTransferController;
use App\Http\Controllers\DocumentShareController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Finance\BankAccountController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Finance\RevenueController;
use App\Http\Controllers\Finance\TransferController;
use App\Http\Controllers\HR\EmployeeController;
use App\Http\Controllers\HR\HrOperationsController;
use App\Http\Controllers\HR\HrSettingsController;
use App\Http\Controllers\HR\PayrollController;
use App\Http\Controllers\HR\SelfServiceController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\InventoryOperationController;
use App\Http\Controllers\InventoryReportController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Projects as PR;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\Purchases as P;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Sales as S;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Http\Controllers\SuperAdmin\PlatformController;
use App\Http\Controllers\SuperAdmin\SuperAdminDashboardController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\ZatcaController;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Route;

// Home redirect
Route::get('/', function () {
    if (auth()->check()) {
        if (auth()->user()->isSuperAdmin() && ! app(TenantManager::class)->isImpersonating()) {
            return redirect()->route('super-admin.dashboard');
        }

        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

// Localization Switcher
// Customer-facing signed links (no login): document PDF and account statement.
Route::middleware(['signed', 'throttle:60,1'])->prefix('share/{company}')->whereNumber('company')->name('share.')->group(function () {
    Route::get('/pdf/{type}/{id}', [DocumentShareController::class, 'sharedPdf'])->whereNumber('id')->name('pdf');
    Route::get('/statement/{customer}', [DocumentShareController::class, 'portal'])->whereNumber('customer')->name('statement');
});

Route::post('/locale/switch', [LocaleController::class, 'switch'])->name('locale.switch');

// Google OAuth
Route::get('/auth/google', [GoogleAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// Authenticated ERP Routes
Route::middleware(['auth'])->group(function () {
    // 2. Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Own two-factor authentication (profile page).
    Route::prefix('user/two-factor')->name('two-factor.')->middleware('throttle:20,1')->group(function () {
        Route::post('/enable', [TwoFactorController::class, 'enable'])->name('enable');
        Route::post('/confirm', [TwoFactorController::class, 'confirm'])->name('confirm');
        Route::post('/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('recovery-codes');
        Route::delete('/', [TwoFactorController::class, 'disable'])->name('disable');
    });

    // 3. Super Admin Routes
    Route::prefix('super-admin')->name('super-admin.')->group(function () {
        // Leave Impersonation route - requires auth + must be actually impersonating
        Route::post('/impersonate/leave', [ImpersonationController::class, 'leave'])
            ->name('impersonate.leave')
            ->middleware('super_admin');

        Route::middleware(['super_admin', 'two_factor'])->group(function () {
            Route::get('/dashboard', [SuperAdminDashboardController::class, 'index'])->name('dashboard');
            Route::post('/impersonate/{company}', [ImpersonationController::class, 'enter'])->name('impersonate.enter');
            Route::get('/companies/{company}', [PlatformController::class, 'company'])->name('companies.show');
            Route::put('/companies/{company}', [PlatformController::class, 'updateCompany'])->name('companies.update');
            Route::post('/companies/{company}/verify-domain', [PlatformController::class, 'verifyDomain'])->name('companies.verify-domain');
            Route::get('/plans', [PlatformController::class, 'plans'])->name('plans');
            Route::post('/plans', [PlatformController::class, 'savePlan'])->name('plans.store');
            Route::put('/plans/{plan}', [PlatformController::class, 'savePlan'])->name('plans.update');
            Route::get('/invoices', [PlatformController::class, 'invoices'])->name('invoices');
            Route::post('/invoices/{invoice}/confirm', [PlatformController::class, 'confirmInvoice'])->name('invoices.confirm');
            Route::post('/invoices/{invoice}/void', [PlatformController::class, 'voidInvoice'])->name('invoices.void');
            Route::get('/audit', [PlatformController::class, 'audit'])->name('audit');
            Route::get('/backups', [PlatformController::class, 'backups'])->name('backups');
            Route::post('/backups', [PlatformController::class, 'runBackup'])->middleware('throttle:6,1')->name('backups.run');
        });
    });

});

// Company (tenant) ERP routes: authenticated user + resolved tenant required.
Route::middleware(['auth', 'two_factor', 'tenant', 'plan', 'erp.authorize', 'throttle:tenant-web'])->group(function () {
    // 1. Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Type-ahead lookups for document forms (bounded results).
    Route::prefix('lookup')->name('lookup.')->group(function () {
        Route::get('/customers', [LookupController::class, 'customers'])->name('customers');
        Route::get('/products', [LookupController::class, 'products'])->name('products');
        Route::get('/suppliers', [LookupController::class, 'suppliers'])->name('suppliers');
        Route::get('/price', [LookupController::class, 'price'])->name('price');
    });

    // Private tenant files (attachments) — streamed only to the owning company.
    Route::get('/files/{token}', [FileController::class, 'show'])
        ->where('token', '.*')
        ->name('files.show');

    // Official PDF and "send to customer" for every customer-facing document
    // (type checked against DocumentSharing::TYPES and its own permission).
    Route::prefix('documents/{type}/{id}')->whereNumber('id')->name('documents.')->group(function () {
        Route::get('/pdf', [DocumentShareController::class, 'pdf'])->name('pdf');
        Route::get('/dispatches', [DocumentShareController::class, 'dispatches'])->name('dispatches');
        Route::post('/send', [DocumentShareController::class, 'send'])->middleware('throttle:30,1')->name('send');
    });

    // Company logo, streamed by the app so it never depends on the public/storage symlink.
    Route::get('/company/logo', [CompanySettingController::class, 'showLogo'])->name('company.logo');

    // 4. Sales Module
    Route::prefix('sales')->name('sales.')->group(function () {
        // Invoices
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoices', [InvoiceController::class, 'store'])
            ->name('invoices.store')
            ->middleware('throttle:invoices');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

        // Quotations
        Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('/quotations/create', [QuotationController::class, 'create'])->name('quotations.create');
        Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
        Route::post('/quotations/{quotation}/convert', [QuotationController::class, 'convertToInvoice'])->name('quotations.convert');

        // Clients (Customers)
        Route::get('/clients', [CustomerController::class, 'index'])->name('clients.index');
        Route::get('/clients/create', [CustomerController::class, 'create'])->name('clients.create');
        Route::post('/clients', [CustomerController::class, 'store'])->name('clients.store');
        Route::post('/clients/upload-attachment', [CustomerController::class, 'uploadAttachment'])->name('clients.upload-attachment');
        Route::get('/clients/{client}', [CustomerController::class, 'show'])->name('clients.show');
        Route::get('/clients/{client}/statement', [CustomerController::class, 'statement'])->name('clients.statement');
        Route::get('/clients/{client}/edit', [CustomerController::class, 'edit'])->name('clients.edit');
        Route::put('/clients/{client}', [CustomerController::class, 'update'])->name('clients.update');
        Route::delete('/clients/{client}', [CustomerController::class, 'destroy'])->name('clients.destroy');

        // Returns & Payments
        Route::get('/returns', [CreditNoteController::class, 'index'])->name('returns.index');
        Route::get('/returns/create', [CreditNoteController::class, 'create'])->name('returns.create');
        Route::post('/returns', [CreditNoteController::class, 'store'])->name('returns.store');
        Route::get('/returns/{creditNote}', [CreditNoteController::class, 'show'])->name('returns.show');
        Route::get('/payments', [CustomerPaymentController::class, 'index'])->name('payments.index');
        Route::post('/payments', [CustomerPaymentController::class, 'store'])->name('payments.store');
        Route::post('/payments/{payment}/allocate', [CustomerPaymentController::class, 'allocate'])->name('payments.allocate');

        // Sales orders → delivery notes → invoices
        Route::get('/orders', [S\SalesOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/create', [S\SalesOrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [S\SalesOrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [S\SalesOrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/approve', [S\SalesOrderController::class, 'approve'])->name('orders.approve');
        Route::post('/orders/{order}/reject', [S\SalesOrderController::class, 'reject'])->name('orders.reject');
        Route::post('/orders/{order}/cancel', [S\SalesOrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('/orders/{order}/deliver', [S\SalesOrderController::class, 'deliver'])->name('orders.deliver');
        Route::post('/orders/{order}/invoice', [S\SalesOrderController::class, 'invoice'])->name('orders.invoice');
        Route::get('/deliveries', [S\DeliveryNoteController::class, 'index'])->name('deliveries.index');
        Route::get('/deliveries/{delivery}', [S\DeliveryNoteController::class, 'show'])->name('deliveries.show');
        Route::post('/deliveries/{delivery}/invoice', [S\DeliveryNoteController::class, 'invoice'])->name('deliveries.invoice');
        Route::get('/debit-notes', [S\DebitNoteController::class, 'index'])->name('debit-notes.index');
        Route::post('/debit-notes', [S\DebitNoteController::class, 'store'])->name('debit-notes.store');
        Route::get('/price-lists', [S\PriceListController::class, 'index'])->name('price-lists.index');
        Route::post('/price-lists', [S\PriceListController::class, 'store'])->name('price-lists.store');
        Route::put('/price-lists/{priceList}', [S\PriceListController::class, 'update'])->name('price-lists.update');
        Route::delete('/price-lists/{priceList}', [S\PriceListController::class, 'destroy'])->name('price-lists.destroy');
    });

    // 5. POS Module
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::post('/session/open', [PosController::class, 'openSession'])->name('session.open');
        Route::post('/session/{session}/close', [PosController::class, 'closeSession'])->name('session.close');
        Route::post('/order', [PosController::class, 'processOrder'])
            ->name('order.process')
            ->middleware('throttle:pos-orders');
        Route::get('/history', [PosController::class, 'history'])->name('history');
        Route::get('/products/search', [PosController::class, 'productSearch'])->name('products.search');
    });

    // 6. Inventory Module
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::post('/products/{product}/units', [ProductController::class, 'storeUnit'])->name('products.units.store');
        Route::delete('/products/{product}/units/{unit}', [ProductController::class, 'destroyUnit'])->name('products.units.destroy');
        Route::put('/products/{product}/kit', [ProductController::class, 'syncKit'])->name('products.kit');

        // Stock transfers (two-step)
        Route::get('/stock-transfers', [StockTransferController::class, 'index'])->name('stock-transfers.index');
        Route::post('/stock-transfers', [StockTransferController::class, 'store'])->name('stock-transfers.store');
        Route::get('/stock-transfers/{transfer}', [StockTransferController::class, 'show'])->name('stock-transfers.show');
        Route::post('/stock-transfers/{transfer}/send', [StockTransferController::class, 'send'])->name('stock-transfers.send');
        Route::post('/stock-transfers/{transfer}/receive', [StockTransferController::class, 'receive'])->name('stock-transfers.receive');
        Route::post('/stock-transfers/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('stock-transfers.cancel');

        // Assembly / BOM
        Route::get('/assembly', [AssemblyController::class, 'index'])->name('assembly.index');
        Route::post('/assembly/boms', [AssemblyController::class, 'storeBom'])->name('assembly.boms.store');
        Route::post('/assembly/orders', [AssemblyController::class, 'storeOrder'])->name('assembly.orders.store');

        // Inventory reports
        Route::get('/reports/item-card', [InventoryReportController::class, 'itemCard'])->name('reports.item-card');
        Route::get('/reports/valuation', [InventoryReportController::class, 'valuation'])->name('reports.valuation');
        Route::get('/reports/reorder', [InventoryReportController::class, 'reorder'])->name('reports.reorder');
        Route::get('/reports/slow-moving', [InventoryReportController::class, 'slowMoving'])->name('reports.slow-moving');
        Route::get('/reports/expiry', [InventoryReportController::class, 'expiry'])->name('reports.expiry');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::get('/operations', [InventoryOperationController::class, 'index'])->name('operations.index');
        Route::post('/operations/transfer', [InventoryOperationController::class, 'transfer'])->name('operations.transfer');
        Route::post('/operations/adjust', [InventoryOperationController::class, 'adjust'])->name('operations.adjust');
        Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
        Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
        Route::get('/counts', [StockCountController::class, 'index'])->name('counts.index');
        Route::post('/counts', [StockCountController::class, 'store'])->name('counts.store');
        Route::get('/counts/{count}', [StockCountController::class, 'show'])->name('counts.show');
        Route::put('/counts/{count}', [StockCountController::class, 'update'])->name('counts.update');
        Route::post('/counts/{count}/approve', [StockCountController::class, 'approve'])->name('counts.approve');
        Route::post('/counts/{count}/cancel', [StockCountController::class, 'cancel'])->name('counts.cancel');
    });

    // 7. Purchases Module
    Route::prefix('purchases')->name('purchases.')->group(function () {
        // Suppliers
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

        // Purchase Invoices
        Route::get('/invoices', [PurchaseInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/create', [PurchaseInvoiceController::class, 'create'])->name('invoices.create');
        Route::get('/invoices/from-receipts', [PurchaseInvoiceController::class, 'fromReceipts'])->name('invoices.from-receipts');
        Route::post('/invoices/from-receipts', [PurchaseInvoiceController::class, 'storeFromReceipts'])->name('invoices.store-from-receipts');
        Route::post('/invoices', [PurchaseInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoices/{invoice}', [PurchaseInvoiceController::class, 'show'])->name('invoices.show');
        Route::delete('/invoices/{invoice}', [PurchaseInvoiceController::class, 'destroy'])->name('invoices.destroy');

        // Purchase Orders
        Route::get('/orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/create', [PurchaseOrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [PurchaseOrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [PurchaseOrderController::class, 'show'])->name('orders.show');
        Route::get('/orders/{order}/edit', [PurchaseOrderController::class, 'edit'])->name('orders.edit');
        Route::put('/orders/{order}', [PurchaseOrderController::class, 'update'])->name('orders.update');
        Route::post('/orders/{order}/submit', [PurchaseOrderController::class, 'submit'])->name('orders.submit');
        Route::post('/orders/{order}/approve', [PurchaseOrderController::class, 'approve'])->name('orders.approve');
        Route::post('/orders/{order}/reject', [PurchaseOrderController::class, 'reject'])->name('orders.reject');
        Route::post('/orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('/orders/{order}/convert', [PurchaseOrderController::class, 'convertToInvoice'])->name('orders.convert');
        Route::post('/orders/{order}/receive', [P\GoodsReceiptController::class, 'store'])->name('orders.receive');

        // Purchase requests
        Route::get('/requests', [P\PurchaseRequestController::class, 'index'])->name('requests.index');
        Route::post('/requests', [P\PurchaseRequestController::class, 'store'])->name('requests.store');
        Route::get('/requests/{purchaseRequest}', [P\PurchaseRequestController::class, 'show'])->name('requests.show');
        Route::post('/requests/{purchaseRequest}/approve', [P\PurchaseRequestController::class, 'approve'])->name('requests.approve');
        Route::post('/requests/{purchaseRequest}/reject', [P\PurchaseRequestController::class, 'reject'])->name('requests.reject');
        Route::post('/requests/{purchaseRequest}/convert', [P\PurchaseRequestController::class, 'convert'])->name('requests.convert');

        // Goods receipts, returns, landed costs
        Route::get('/receipts', [P\GoodsReceiptController::class, 'index'])->name('receipts.index');
        Route::get('/receipts/{receipt}', [P\GoodsReceiptController::class, 'show'])->name('receipts.show');
        Route::get('/returns', [P\PurchaseReturnController::class, 'index'])->name('returns.index');
        Route::get('/returns/create', [P\PurchaseReturnController::class, 'create'])->name('returns.create');
        Route::post('/returns', [P\PurchaseReturnController::class, 'store'])->name('returns.store');
        Route::get('/returns/{return}', [P\PurchaseReturnController::class, 'show'])->name('returns.show');
        Route::get('/landed-costs', [P\LandedCostController::class, 'index'])->name('landed-costs.index');
        Route::post('/landed-costs', [P\LandedCostController::class, 'store'])->name('landed-costs.store');

        // Supplier Payments
        Route::get('/payments', [SupplierPaymentController::class, 'index'])->name('payments.index');
        Route::post('/payments', [SupplierPaymentController::class, 'store'])->name('payments.store');
    });

    // Contracting & projects
    Route::prefix('projects')->name('projects.')->group(function () {
        Route::get('/', [PR\ProjectController::class, 'index'])->name('index');
        Route::get('/create', [PR\ProjectController::class, 'create'])->name('create');
        Route::post('/', [PR\ProjectController::class, 'store'])->name('store');
        Route::get('/guarantees', [PR\GuaranteeController::class, 'index'])->name('guarantees.index');
        Route::post('/guarantees', [PR\GuaranteeController::class, 'store'])->name('guarantees.store');
        Route::put('/guarantees/{guarantee}', [PR\GuaranteeController::class, 'update'])->name('guarantees.update');
        Route::post('/subcontracts/{subcontract}/advance', [PR\ProjectCostController::class, 'subcontractAdvance'])->name('subcontracts.advance');
        Route::post('/subcontracts/{subcontract}/certify', [PR\ProjectCostController::class, 'certify'])->name('subcontracts.certify');
        Route::get('/{project}', [PR\ProjectController::class, 'show'])->name('show');
        Route::put('/{project}', [PR\ProjectController::class, 'update'])->name('update');
        Route::put('/{project}/boq', [PR\ProjectController::class, 'updateBoq'])->name('boq.update');
        Route::post('/{project}/advance', [PR\ProjectController::class, 'advance'])->name('advance');
        Route::post('/{project}/retention/release', [PR\ProjectController::class, 'releaseRetention'])->name('retention.release');
        Route::post('/{project}/variations', [PR\ProjectController::class, 'storeVariation'])->name('variations.store');
        Route::post('/{project}/variations/{variation}/approve', [PR\ProjectController::class, 'approveVariation'])->name('variations.approve');
        Route::post('/{project}/variations/{variation}/reject', [PR\ProjectController::class, 'rejectVariation'])->name('variations.reject');
        Route::post('/{project}/daily-reports', [PR\ProjectController::class, 'storeDailyReport'])->name('daily-reports.store');
        Route::post('/{project}/billings', [PR\ProjectBillingController::class, 'store'])->name('billings.store');
        Route::get('/{project}/billings/{billing}', [PR\ProjectBillingController::class, 'show'])->name('billings.show');
        Route::post('/{project}/billings/{billing}/approve', [PR\ProjectBillingController::class, 'approve'])->name('billings.approve');
        Route::delete('/{project}/billings/{billing}', [PR\ProjectBillingController::class, 'destroy'])->name('billings.destroy');
        Route::post('/{project}/materials', [PR\ProjectCostController::class, 'materials'])->name('materials');
        Route::post('/{project}/costs', [PR\ProjectCostController::class, 'store'])->name('costs.store');
        Route::post('/{project}/subcontracts', [PR\ProjectCostController::class, 'storeSubcontract'])->name('subcontracts.store');
    });

    // 8. Finance Module
    Route::prefix('finance')->name('finance.')->group(function () {
        // Expenses
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

        // Revenues
        Route::get('/revenues', [RevenueController::class, 'index'])->name('revenues.index');
        Route::post('/revenues', [RevenueController::class, 'store'])->name('revenues.store');
        Route::delete('/revenues/{revenue}', [RevenueController::class, 'destroy'])->name('revenues.destroy');

        // Banks & Treasuries
        Route::get('/banks', [BankAccountController::class, 'index'])->name('banks.index');
        Route::post('/banks', [BankAccountController::class, 'store'])->name('banks.store');
        Route::delete('/banks/{bank}', [BankAccountController::class, 'destroy'])->name('banks.destroy');

        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
    });

    // 9. Accounting Module
    Route::prefix('accounting')->name('accounting.')->group(function () {
        // Chart of Accounts
        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

        // Journal Entries
        Route::get('/journals', [JournalEntryController::class, 'index'])->name('journals.index');
        Route::post('/journals', [JournalEntryController::class, 'store'])->name('journals.store');
        Route::get('/journals/{entry}', [JournalEntryController::class, 'show'])->name('journals.show');
        Route::post('/journals/{entry}/post', [JournalEntryController::class, 'post'])->name('journals.post');
        Route::post('/journals/{entry}/reverse', [JournalEntryController::class, 'reverse'])->name('journals.reverse');
        Route::delete('/journals/{entry}', [JournalEntryController::class, 'destroy'])->name('journals.destroy');

        // Recurring journals
        Route::get('/recurring', [A\RecurringJournalController::class, 'index'])->name('recurring.index');
        Route::post('/recurring', [A\RecurringJournalController::class, 'store'])->name('recurring.store');
        Route::put('/recurring/{recurring}', [A\RecurringJournalController::class, 'update'])->name('recurring.update');
        Route::delete('/recurring/{recurring}', [A\RecurringJournalController::class, 'destroy'])->name('recurring.destroy');

        // Cost centers
        Route::get('/cost-centers', [A\CostCenterController::class, 'index'])->name('cost-centers.index');
        Route::post('/cost-centers', [A\CostCenterController::class, 'store'])->name('cost-centers.store');
        Route::put('/cost-centers/{costCenter}', [A\CostCenterController::class, 'update'])->name('cost-centers.update');
        Route::delete('/cost-centers/{costCenter}', [A\CostCenterController::class, 'destroy'])->name('cost-centers.destroy');

        // Fiscal years & periods
        Route::get('/fiscal-years', [A\FiscalYearController::class, 'index'])->name('fiscal-years.index');
        Route::post('/fiscal-years', [A\FiscalYearController::class, 'store'])->name('fiscal-years.store');
        Route::put('/fiscal-periods/{period}', [A\FiscalYearController::class, 'updatePeriod'])->name('fiscal-periods.update');
        Route::post('/fiscal-years/{year}/close', [A\FiscalYearController::class, 'close'])->name('fiscal-years.close');
        Route::post('/fiscal-years/{year}/reopen', [A\FiscalYearController::class, 'reopen'])->name('fiscal-years.reopen');

        // Account mapping
        Route::get('/mappings', [A\AccountMappingController::class, 'index'])->name('mappings.index');
        Route::put('/mappings', [A\AccountMappingController::class, 'update'])->name('mappings.update');

        // Fixed assets
        Route::get('/fixed-assets', [A\FixedAssetController::class, 'index'])->name('fixed-assets.index');
        Route::post('/fixed-assets', [A\FixedAssetController::class, 'store'])->name('fixed-assets.store');
        Route::post('/fixed-assets/categories', [A\FixedAssetController::class, 'storeCategory'])->name('fixed-assets.categories.store');
        Route::post('/fixed-assets/depreciate', [A\FixedAssetController::class, 'depreciate'])->name('fixed-assets.depreciate');
        Route::get('/fixed-assets/{asset}', [A\FixedAssetController::class, 'show'])->name('fixed-assets.show');
        Route::post('/fixed-assets/{asset}/dispose', [A\FixedAssetController::class, 'dispose'])->name('fixed-assets.dispose');

        // Cheques
        Route::get('/cheques', [A\ChequeController::class, 'index'])->name('cheques.index');
        Route::post('/cheques', [A\ChequeController::class, 'store'])->name('cheques.store');
        Route::post('/cheques/{cheque}/transition', [A\ChequeController::class, 'transition'])->name('cheques.transition');

        // Bank reconciliation
        Route::get('/reconciliations', [A\BankReconciliationController::class, 'index'])->name('reconciliations.index');
        Route::post('/reconciliations', [A\BankReconciliationController::class, 'store'])->name('reconciliations.store');
        Route::get('/reconciliations/{reconciliation}', [A\BankReconciliationController::class, 'show'])->name('reconciliations.show');
        Route::put('/reconciliations/{reconciliation}', [A\BankReconciliationController::class, 'update'])->name('reconciliations.update');

        // Budgets
        Route::get('/budgets', [A\BudgetController::class, 'index'])->name('budgets.index');
        Route::post('/budgets', [A\BudgetController::class, 'store'])->name('budgets.store');
        Route::get('/budgets/{budget}', [A\BudgetController::class, 'show'])->name('budgets.show');
        Route::put('/budgets/{budget}', [A\BudgetController::class, 'updateLines'])->name('budgets.update');

        // Financial statements & statutory reports
        Route::get('/reports/income-statement', [A\FinancialReportController::class, 'incomeStatement'])->name('reports.income-statement');
        Route::get('/reports/balance-sheet', [A\FinancialReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
        Route::get('/reports/cash-flow', [A\FinancialReportController::class, 'cashFlow'])->name('reports.cash-flow');
        Route::get('/reports/aging', [A\FinancialReportController::class, 'aging'])->name('reports.aging');
        Route::get('/reports/vat-return', [A\FinancialReportController::class, 'vatReturn'])->name('reports.vat-return');

        // General Ledger
        Route::get('/ledger', [GeneralLedgerController::class, 'index'])->name('ledger.index');

        // Trial Balance
        Route::get('/trial-balance', [TrialBalanceController::class, 'index'])->name('trial-balance.index');
    });

    // 10. Reports Module
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/sales', [ReportController::class, 'sales'])->name('sales');
        Route::get('/inventory', [ReportController::class, 'inventory'])->name('inventory');
        Route::get('/financial', [ReportController::class, 'financial'])->name('financial');
        Route::get('/vat', [ReportController::class, 'vat'])->name('vat');
    });

    // 11. Users & Roles
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::put('/users/{user}/permissions', [UserController::class, 'updatePermissions'])->name('users.permissions');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->name('roles.permissions');
    Route::post('/roles/{role}/duplicate', [RoleController::class, 'duplicate'])->name('roles.duplicate');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit.index');

    // Approval workflow rules
    Route::get('/approval-rules', [ApprovalRuleController::class, 'index'])->name('approval-rules.index');
    Route::post('/approval-rules', [ApprovalRuleController::class, 'store'])->name('approval-rules.store');
    Route::delete('/approval-rules/{rule}', [ApprovalRuleController::class, 'destroy'])->name('approval-rules.destroy');

    // 12. HR Module
    Route::prefix('hr')->name('hr.')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');
        Route::post('/employees/{employee}/documents', [EmployeeController::class, 'storeDocument'])->name('employees.documents.store');
        Route::delete('/employees/{employee}/documents/{document}', [EmployeeController::class, 'destroyDocument'])->name('employees.documents.destroy');
        Route::post('/employees/{employee}/pay-items', [EmployeeController::class, 'storePayItem'])->name('employees.pay-items.store');
        Route::delete('/employees/{employee}/pay-items/{item}', [EmployeeController::class, 'destroyPayItem'])->name('employees.pay-items.destroy');

        Route::get('/settings', [HrSettingsController::class, 'show'])->name('settings');
        Route::put('/settings/{group}', [HrSettingsController::class, 'update'])->name('settings.update');
        Route::delete('/settings/{group}', [HrSettingsController::class, 'reset'])->name('settings.reset');
        Route::put('/leave-types/{leaveType}', [HrSettingsController::class, 'updateLeaveType'])->name('leave-types.update');

        Route::get('/organization', [HrOperationsController::class, 'organization'])->name('organization');
        Route::post('/organization/departments', [HrOperationsController::class, 'storeDepartment'])->name('departments.store');
        Route::post('/organization/positions', [HrOperationsController::class, 'storePosition'])->name('positions.store');
        Route::post('/organization/shifts', [HrOperationsController::class, 'storeShift'])->name('shifts.store');

        Route::get('/attendance', [HrOperationsController::class, 'attendance'])->name('attendance.index');
        Route::post('/attendance', [HrOperationsController::class, 'storeAttendance'])->name('attendance.store');
        Route::post('/attendance/import', [HrOperationsController::class, 'importAttendance'])->name('attendance.import');

        Route::get('/leaves', [HrOperationsController::class, 'leaves'])->name('leaves.index');
        Route::post('/leaves', [HrOperationsController::class, 'storeLeave'])->name('leaves.store');
        Route::post('/leaves/{leave}/decide', [HrOperationsController::class, 'decideLeave'])->name('leaves.decide');

        Route::get('/loans', [HrOperationsController::class, 'loans'])->name('loans.index');
        Route::post('/loans', [HrOperationsController::class, 'storeLoan'])->name('loans.store');
        Route::post('/loans/{loan}/decide', [HrOperationsController::class, 'decideLoan'])->name('loans.decide');

        Route::get('/settlements', [HrOperationsController::class, 'settlements'])->name('settlements.index');
        Route::post('/settlements', [HrOperationsController::class, 'storeSettlement'])->name('settlements.store');
        Route::post('/settlements/{settlement}/approve', [HrOperationsController::class, 'approveSettlement'])->name('settlements.approve');
        Route::post('/settlements/{settlement}/pay', [HrOperationsController::class, 'paySettlement'])->name('settlements.pay');
        Route::post('/settlements/provision', [HrOperationsController::class, 'accrueProvision'])->name('settlements.provision');

        Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
        Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store');
        Route::get('/payroll/{payroll}', [PayrollController::class, 'show'])->name('payroll.show');
        Route::post('/payroll/{payroll}/recalculate', [PayrollController::class, 'recalculate'])->name('payroll.recalculate');
        Route::post('/payroll/{payroll}/approve', [PayrollController::class, 'approve'])->name('payroll.approve');
        Route::post('/payroll/{payroll}/pay', [PayrollController::class, 'pay'])->name('payroll.pay');
        Route::post('/payroll/{payroll}/adjustments', [PayrollController::class, 'storeAdjustment'])->name('payroll.adjustments.store');
        Route::delete('/payroll/{payroll}/adjustments/{adjustment}', [PayrollController::class, 'destroyAdjustment'])->name('payroll.adjustments.destroy');
        Route::get('/payroll/{payroll}/wps', [PayrollController::class, 'wps'])->name('payroll.wps');
        Route::get('/payroll/{payroll}/payslips/{item}', [PayrollController::class, 'payslip'])->name('payroll.payslip');

        Route::get('/me', [SelfServiceController::class, 'index'])->name('me');
        Route::post('/me/leaves', [SelfServiceController::class, 'requestLeave'])->name('me.leaves');
        Route::post('/me/loans', [SelfServiceController::class, 'requestLoan'])->name('me.loans');
        Route::get('/me/payslips/{item}', [SelfServiceController::class, 'payslip'])->name('me.payslip');
    });

    // 13. Branches, Templates, Settings
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');

    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::get('/templates/{template}/designer', [TemplateController::class, 'designer'])->name('templates.designer');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::post('/templates/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('templates.duplicate');
    Route::post('/templates/{template}/default', [TemplateController::class, 'setDefault'])->name('templates.default');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
    Route::get('/templates/preview-invoice/{invoice}', [TemplateController::class, 'getInvoicePreview'])->name('templates.preview-invoice');

    Route::get('/settings', [CompanySettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [CompanySettingController::class, 'update'])->name('settings.update');
    Route::post('/settings/logo', [CompanySettingController::class, 'uploadLogo'])->name('settings.logo.upload');
    Route::delete('/settings/logo', [CompanySettingController::class, 'deleteLogo'])->name('settings.logo.delete');
    Route::post('/settings/zatca/onboard', [CompanySettingController::class, 'onboardZatca'])->name('settings.zatca.onboard');

    // Subscription, integrations, notifications, data transfer
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/plan', [BillingController::class, 'requestPlan'])->name('billing.plan');
    Route::put('/billing/domain', [BillingController::class, 'updateDomain'])->name('billing.domain');
    Route::post('/billing/domain/verify', [BillingController::class, 'verifyDomain'])->name('billing.domain.verify');
    Route::post('/billing/export', [BillingController::class, 'exportData'])->name('billing.export');
    Route::get('/billing/export/{export}', [BillingController::class, 'downloadExport'])->name('billing.export.download');

    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('/integrations/tokens', [IntegrationController::class, 'storeToken'])->name('integrations.tokens.store');
    Route::delete('/integrations/tokens/{token}', [IntegrationController::class, 'destroyToken'])->name('integrations.tokens.destroy');
    Route::post('/integrations/webhooks', [IntegrationController::class, 'storeWebhook'])->name('webhooks.store');
    Route::delete('/integrations/webhooks/{webhook}', [IntegrationController::class, 'destroyWebhook'])->name('webhooks.destroy');
    Route::post('/integrations/webhooks/{webhook}/test', [IntegrationController::class, 'testWebhook'])->name('webhooks.test');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{id}', [NotificationController::class, 'read'])->name('notifications.read');

    Route::get('/data-transfer', [DataTransferController::class, 'index'])->name('data-transfer.index');
    Route::get('/data-transfer/{type}/export', [DataTransferController::class, 'export'])->name('data-transfer.export');
    Route::get('/data-transfer/{type}/template', [DataTransferController::class, 'template'])->name('data-transfer.template');
    Route::post('/data-transfer/{type}/import', [DataTransferController::class, 'import'])->name('data-transfer.import');

    // CRM
    Route::prefix('crm')->name('crm.')->group(function () {
        Route::get('/leads', [Crm\LeadController::class, 'index'])->name('leads.index');
        Route::post('/leads', [Crm\LeadController::class, 'store'])->name('leads.store');
        Route::get('/leads/{lead}', [Crm\LeadController::class, 'show'])->name('leads.show');
        Route::put('/leads/{lead}', [Crm\LeadController::class, 'update'])->name('leads.update');
        Route::post('/leads/{lead}/move', [Crm\LeadController::class, 'move'])->name('leads.move');
        Route::post('/leads/{lead}/activities', [Crm\LeadController::class, 'storeActivity'])->name('leads.activities.store');
        Route::post('/leads/{lead}/convert', [Crm\LeadController::class, 'convert'])->name('leads.convert');
    });

    // E-invoicing (ZATCA phase 2)
    Route::prefix('zatca')->name('zatca.')->group(function () {
        Route::get('/', [ZatcaController::class, 'index'])->name('index');
        Route::put('/seller', [ZatcaController::class, 'updateSeller'])->name('seller');
        Route::put('/tax-defaults', [ZatcaController::class, 'updateTaxDefaults'])->name('tax-defaults');
        Route::post('/devices', [ZatcaController::class, 'storeDevice'])->name('devices.store');
        Route::post('/devices/{device}/onboard', [ZatcaController::class, 'onboard'])->name('devices.onboard');
        Route::post('/devices/{device}/renew', [ZatcaController::class, 'renew'])->name('devices.renew');
        Route::post('/devices/{device}/revoke', [ZatcaController::class, 'revoke'])->name('devices.revoke');
        Route::post('/documents/{document}/resubmit', [ZatcaController::class, 'resubmit'])->name('documents.resubmit');
        Route::get('/documents/{document}/xml', [ZatcaController::class, 'xml'])->name('documents.xml');
    });
});

require __DIR__.'/auth.php';
