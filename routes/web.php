<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting as A;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\ActiveContextController;
use App\Http\Controllers\ApprovalRuleController;
use App\Http\Controllers\AssemblyController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LocalSetupController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\Crm;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataTransferController;
use App\Http\Controllers\DocumentCancellationController;
use App\Http\Controllers\DocumentShareController;
use App\Http\Controllers\Equipment as EQ;
use App\Http\Controllers\FeatureRequestController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Finance\BankAccountController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Finance\RevenueController;
use App\Http\Controllers\Finance\TransferController;
use App\Http\Controllers\FreeformTemplateController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HR\EmployeeController;
use App\Http\Controllers\HR\HrOperationsController;
use App\Http\Controllers\HR\HrReportController;
use App\Http\Controllers\HR\HrSettingsController;
use App\Http\Controllers\HR\NationalityController;
use App\Http\Controllers\HR\PayrollController;
use App\Http\Controllers\HR\SelfServiceController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\InventoryOperationController;
use App\Http\Controllers\InventoryReportController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LineImportController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\Manufacturing as Mfg;
use App\Http\Controllers\ModuleSettingsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosRegisterController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Projects as PR;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\Purchases as P;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\RecycleBinController;
use App\Http\Controllers\ReleaseNotesController;
use App\Http\Controllers\Reports\InsightsController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Sales as S;
use App\Http\Controllers\Sales\CommissionController;
use App\Http\Controllers\Sales\CommissionPlanController;
use App\Http\Controllers\Sales\CustomerGroupController;
use App\Http\Controllers\Sales\PromotionController;
use App\Http\Controllers\Sales\RecurringInvoiceController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SelfUpdateController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Http\Controllers\SuperAdmin\PlatformController;
use App\Http\Controllers\SuperAdmin\SuperAdminDashboardController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\ZatcaController;
use App\Models\PlatformPage;
use App\Models\SubscriptionPlan;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Route;

// Home redirect
// Terms of service and privacy policy (public).
Route::get('/legal/{slug}', function (string $slug) {
    $page = PlatformPage::content($slug);

    return response()->view('legal.page', ['page' => $page, 'html' => PlatformPage::html($page['body'])]);
})->whereIn('slug', array_keys(PlatformPage::PAGES))->name('legal.show');

// Uptime monitoring (database, queue, scheduler heartbeat, backup age).
Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('health');

Route::get('/', function () {
    if (auth()->check()) {
        if (auth()->user()->isSuperAdmin() && ! app(TenantManager::class)->isImpersonating()) {
            return redirect()->route('super-admin.dashboard');
        }

        return redirect()->route('dashboard');
    }

    // A local install has no landing page: first run sets the company up, then it is the login.
    if (config('app.local_install')) {
        return redirect()->route(LocalSetupController::isOpen() ? 'setup' : 'login');
    }

    // A company's own subdomain goes to its login; the platform domain shows the landing page.
    if (app(TenantManager::class)->getCompany()) {
        return redirect()->route('login');
    }

    return Inertia\Inertia::render('Landing', [
        'plans' => SubscriptionPlan::where('is_active', true)->orderBy('price_monthly')
            ->get(['id', 'name_ar', 'description_ar', 'price_monthly', 'price_yearly', 'max_users', 'max_branches', 'max_invoices_per_month', 'modules', 'is_popular', 'trial_days']),
        'countries' => count(config('countries')),
    ]);
})->name('home');

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
            Route::get('/invoices/{invoice}', [PlatformController::class, 'showInvoice'])->name('invoices.show');
            Route::get('/legal', [PlatformController::class, 'legal'])->name('legal');
            Route::put('/legal/{slug}', [PlatformController::class, 'updateLegal'])->name('legal.update');
            Route::post('/invoices/{invoice}/confirm', [PlatformController::class, 'confirmInvoice'])->name('invoices.confirm');
            Route::post('/invoices/{invoice}/void', [PlatformController::class, 'voidInvoice'])->name('invoices.void');
            Route::get('/audit', [PlatformController::class, 'audit'])->name('audit');
            Route::get('/backups', [PlatformController::class, 'backups'])->name('backups');
            Route::post('/backups', [PlatformController::class, 'runBackup'])->middleware('throttle:6,1')->name('backups.run');
        });
    });

});

// Company (tenant) ERP routes: authenticated user + resolved tenant required.
Route::middleware(['auth', 'verified', 'two_factor', 'tenant', 'plan', 'country.feature', 'erp.authorize', 'throttle:tenant-web'])->group(function () {
    // 1. Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/search', SearchController::class)->name('search');

    // Working branch and fiscal year (sidebar «سياق العمل» card).
    Route::post('/context', [ActiveContextController::class, 'update'])->name('context.update');
    Route::post('/context/branch', [ActiveContextController::class, 'branch'])->name('context.branch');
    Route::post('/context/fiscal-year', [ActiveContextController::class, 'fiscalYear'])->name('context.fiscal-year');
    Route::get('/system/update', [SelfUpdateController::class, 'status'])->name('system.update.status');
    Route::post('/system/update', [SelfUpdateController::class, 'apply'])->middleware('throttle:6,1')->name('system.update.apply');

    // Type-ahead lookups for document forms (bounded results).
    Route::prefix('lookup')->name('lookup.')->group(function () {
        Route::get('/customers', [LookupController::class, 'customers'])->name('customers');
        Route::get('/products', [LookupController::class, 'products'])->name('products');
        Route::get('/suppliers', [LookupController::class, 'suppliers'])->name('suppliers');
        Route::get('/list/{name}', [LookupController::class, 'list'])->name('list');
        Route::get('/price', [LookupController::class, 'price'])->name('price');
        Route::post('/lines/import', [LineImportController::class, 'import'])->name('lines.import');
        Route::get('/lines/template', [LineImportController::class, 'template'])->name('lines.template');
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
        Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

        // Quotations
        Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('/quotations/create', [QuotationController::class, 'create'])->name('quotations.create');
        Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
        Route::delete('/quotations/{quotation}', [QuotationController::class, 'destroy'])->name('quotations.destroy');
        Route::post('/quotations/{quotation}/convert', [QuotationController::class, 'convertToInvoice'])->name('quotations.convert');

        // Clients (Customers)
        Route::get('/customer-groups', [CustomerGroupController::class, 'index'])->name('customer-groups.index');
        Route::get('/customer-groups/create', [CustomerGroupController::class, 'create'])->name('customer-groups.create');
        Route::post('/customer-groups', [CustomerGroupController::class, 'store'])->name('customer-groups.store');
        Route::get('/customer-groups/{group}/edit', [CustomerGroupController::class, 'edit'])->name('customer-groups.edit');
        Route::put('/customer-groups/{group}', [CustomerGroupController::class, 'update'])->name('customer-groups.update');
        Route::delete('/customer-groups/{group}', [CustomerGroupController::class, 'destroy'])->name('customer-groups.destroy');
        Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
        Route::get('/promotions/create', [PromotionController::class, 'create'])->name('promotions.create');
        Route::post('/promotions', [PromotionController::class, 'store'])->name('promotions.store');
        Route::get('/promotions/{promotion}/edit', [PromotionController::class, 'edit'])->name('promotions.edit');
        Route::put('/promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
        Route::delete('/promotions/{promotion}', [PromotionController::class, 'destroy'])->name('promotions.destroy');
        Route::get('/commissions', [CommissionController::class, 'index'])->name('commissions.index');
        Route::post('/commissions/post', [CommissionController::class, 'post'])->name('commissions.post');
        Route::get('/commission-plans', [CommissionPlanController::class, 'index'])->name('commission-plans.index');
        Route::get('/commission-plans/create', [CommissionPlanController::class, 'create'])->name('commission-plans.create');
        Route::post('/commission-plans', [CommissionPlanController::class, 'store'])->name('commission-plans.store');
        Route::get('/commission-plans/{plan}/edit', [CommissionPlanController::class, 'edit'])->name('commission-plans.edit');
        Route::put('/commission-plans/{plan}', [CommissionPlanController::class, 'update'])->name('commission-plans.update');
        Route::delete('/commission-plans/{plan}', [CommissionPlanController::class, 'destroy'])->name('commission-plans.destroy');
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
        Route::get('/payments/open-invoices', [CustomerPaymentController::class, 'openInvoices'])->name('payments.open-invoices');

        Route::get('/recurring', [RecurringInvoiceController::class, 'index'])->name('recurring.index');
        Route::post('/recurring', [RecurringInvoiceController::class, 'store'])->name('recurring.store');
        Route::put('/recurring/{recurring}', [RecurringInvoiceController::class, 'update'])->name('recurring.update');
        Route::post('/recurring/{recurring}/toggle', [RecurringInvoiceController::class, 'toggle'])->name('recurring.toggle');
        Route::post('/recurring/{recurring}/run', [RecurringInvoiceController::class, 'run'])->name('recurring.run');
        Route::delete('/recurring/{recurring}', [RecurringInvoiceController::class, 'destroy'])->name('recurring.destroy');
        Route::post('/payments/{payment}/allocate', [CustomerPaymentController::class, 'allocate'])->name('payments.allocate');
        Route::post('/payments/{payment}/cancel', [DocumentCancellationController::class, 'customerPayment'])->name('payments.cancel');

        // Sales orders → delivery notes → invoices
        Route::get('/orders', [S\SalesOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/create', [S\SalesOrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [S\SalesOrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [S\SalesOrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/approve', [S\SalesOrderController::class, 'approve'])->name('orders.approve');
        Route::post('/orders/{order}/reject', [S\SalesOrderController::class, 'reject'])->name('orders.reject');
        Route::post('/orders/{order}/cancel', [S\SalesOrderController::class, 'cancel'])->name('orders.cancel');
        Route::delete('/orders/{order}', [S\SalesOrderController::class, 'destroy'])->name('orders.destroy');
        Route::post('/orders/{order}/deliver', [S\SalesOrderController::class, 'deliver'])->name('orders.deliver');
        Route::post('/orders/{order}/invoice', [S\SalesOrderController::class, 'invoice'])->name('orders.invoice');
        Route::get('/deliveries', [S\DeliveryNoteController::class, 'index'])->name('deliveries.index');
        Route::get('/deliveries/{delivery}', [S\DeliveryNoteController::class, 'show'])->name('deliveries.show');
        Route::post('/deliveries/{delivery}/invoice', [S\DeliveryNoteController::class, 'invoice'])->name('deliveries.invoice');
        Route::post('/deliveries/{delivery}/cancel', [DocumentCancellationController::class, 'deliveryNote'])->name('deliveries.cancel');
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
        Route::get('/registers', [PosRegisterController::class, 'index'])->name('registers.index');
        Route::get('/registers/create', [PosRegisterController::class, 'create'])->name('registers.create');
        Route::post('/registers', [PosRegisterController::class, 'store'])->name('registers.store');
        Route::get('/registers/{register}/edit', [PosRegisterController::class, 'edit'])->name('registers.edit');
        Route::put('/registers/{register}', [PosRegisterController::class, 'update'])->name('registers.update');
        Route::delete('/registers/{register}', [PosRegisterController::class, 'destroy'])->name('registers.destroy');
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
        Route::post('/units', [UnitController::class, 'store'])->name('units.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::get('/operations', [InventoryOperationController::class, 'index'])->name('operations.index');
        Route::get('/operations/transfer', [InventoryOperationController::class, 'transferForm'])->name('operations.transfer.create');
        Route::get('/operations/adjust', [InventoryOperationController::class, 'adjustForm'])->name('operations.adjust.create');
        Route::post('/operations/transfer', [InventoryOperationController::class, 'transfer'])->name('operations.transfer');
        Route::post('/operations/adjust', [InventoryOperationController::class, 'adjust'])->name('operations.adjust');
        Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::get('/warehouses/create', [WarehouseController::class, 'create'])->name('warehouses.create');
        Route::get('/warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->name('warehouses.edit');
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
        Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
        Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
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
        Route::get('/invoices/{invoice}/edit', [PurchaseInvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/invoices/{invoice}', [PurchaseInvoiceController::class, 'update'])->name('invoices.update');

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
        Route::delete('/orders/{order}', [PurchaseOrderController::class, 'destroy'])->name('orders.destroy');
        Route::get('/orders/{order}/invoice', [PurchaseOrderController::class, 'invoiceForm'])->name('orders.invoice-form');
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
        Route::post('/receipts/{receipt}/cancel', [DocumentCancellationController::class, 'goodsReceipt'])->name('receipts.cancel');
        Route::get('/returns', [P\PurchaseReturnController::class, 'index'])->name('returns.index');
        Route::get('/returns/create', [P\PurchaseReturnController::class, 'create'])->name('returns.create');
        Route::post('/returns', [P\PurchaseReturnController::class, 'store'])->name('returns.store');
        Route::get('/returns/{return}', [P\PurchaseReturnController::class, 'show'])->name('returns.show');
        Route::get('/landed-costs', [P\LandedCostController::class, 'index'])->name('landed-costs.index');
        Route::post('/landed-costs', [P\LandedCostController::class, 'store'])->name('landed-costs.store');

        // Supplier Payments
        Route::get('/payments', [SupplierPaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/create', [SupplierPaymentController::class, 'create'])->name('payments.create');
        Route::post('/payments', [SupplierPaymentController::class, 'store'])->name('payments.store');
        Route::post('/payments/{payment}/cancel', [DocumentCancellationController::class, 'supplierPayment'])->name('payments.cancel');
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

    // Equipment: rental, daily meter readings, periodic maintenance
    Route::prefix('equipment')->name('equipment.')->group(function () {
        Route::get('/', [EQ\EquipmentController::class, 'index'])->name('index');
        Route::post('/', [EQ\EquipmentController::class, 'store'])->name('store');
        Route::get('/readings', [EQ\ReadingController::class, 'index'])->name('readings.index');
        Route::post('/readings', [EQ\ReadingController::class, 'store'])->name('readings.store');
        Route::delete('/readings/{reading}', [EQ\ReadingController::class, 'destroy'])->name('readings.destroy');
        Route::get('/rentals', [EQ\RentalController::class, 'index'])->name('rentals.index');
        Route::post('/rentals', [EQ\RentalController::class, 'store'])->name('rentals.store');
        Route::get('/rentals/{rental}', [EQ\RentalController::class, 'show'])->name('rentals.show');
        Route::put('/rentals/{rental}', [EQ\RentalController::class, 'update'])->name('rentals.update');
        Route::post('/rentals/{rental}/close', [EQ\RentalController::class, 'close'])->name('rentals.close');
        Route::get('/rentals/{rental}/preview', [EQ\RentalController::class, 'preview'])->name('rentals.preview');
        Route::post('/rentals/{rental}/bill', [EQ\RentalController::class, 'bill'])->name('rentals.bill');
        Route::get('/maintenance', [EQ\MaintenanceOrderController::class, 'index'])->name('maintenance.index');
        Route::get('/maintenance/dashboard', [EQ\MaintenanceOrderController::class, 'dashboard'])->name('maintenance.dashboard');
        Route::get('/maintenance/stock', [EQ\MaintenanceOrderController::class, 'stock'])->name('maintenance.stock');
        Route::post('/maintenance', [EQ\MaintenanceOrderController::class, 'store'])->name('maintenance.store');
        Route::get('/maintenance/{order}', [EQ\MaintenanceOrderController::class, 'show'])->name('maintenance.show');
        Route::post('/maintenance/{order}/issue', [EQ\MaintenanceOrderController::class, 'issue'])->name('maintenance.issue');
        Route::post('/maintenance/{order}/complete', [EQ\MaintenanceOrderController::class, 'complete'])->name('maintenance.complete');
        Route::delete('/maintenance/{order}', [EQ\MaintenanceOrderController::class, 'destroy'])->name('maintenance.destroy');
        Route::get('/reports', [EQ\EquipmentReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [EQ\EquipmentReportController::class, 'export'])->name('reports.export');
        Route::put('/schedules/{schedule}', [EQ\EquipmentController::class, 'updateSchedule'])->name('schedules.update');
        Route::delete('/schedules/{schedule}', [EQ\EquipmentController::class, 'destroySchedule'])->name('schedules.destroy');
        Route::get('/{equipment}', [EQ\EquipmentController::class, 'show'])->name('show');
        Route::put('/{equipment}', [EQ\EquipmentController::class, 'update'])->name('update');
        Route::delete('/{equipment}', [EQ\EquipmentController::class, 'destroy'])->name('destroy');
        Route::post('/{equipment}/schedules', [EQ\EquipmentController::class, 'storeSchedule'])->name('schedules.store');
        Route::post('/{equipment}/schedules/copy', [EQ\EquipmentController::class, 'copySchedules'])->name('schedules.copy');
        Route::get('/{equipment}/supplier-preview', [EQ\RentalController::class, 'supplierPreview'])->name('supplier.preview');
        Route::post('/{equipment}/supplier-bill', [EQ\RentalController::class, 'supplierBill'])->name('supplier.bill');
    });

    // 8. Finance Module
    Route::prefix('finance')->name('finance.')->group(function () {
        // Expenses
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');

        // Revenues
        Route::get('/revenues', [RevenueController::class, 'index'])->name('revenues.index');
        Route::get('/revenues/create', [RevenueController::class, 'create'])->name('revenues.create');
        Route::post('/revenues', [RevenueController::class, 'store'])->name('revenues.store');
        Route::delete('/revenues/{revenue}', [RevenueController::class, 'destroy'])->name('revenues.destroy');
        Route::get('/revenues/{revenue}/edit', [RevenueController::class, 'edit'])->name('revenues.edit');
        Route::put('/revenues/{revenue}', [RevenueController::class, 'update'])->name('revenues.update');

        // Banks & Treasuries
        Route::get('/banks', [BankAccountController::class, 'index'])->name('banks.index');
        Route::get('/banks/create', [BankAccountController::class, 'create'])->name('banks.create');
        Route::post('/banks', [BankAccountController::class, 'store'])->name('banks.store');
        Route::delete('/banks/{bank}', [BankAccountController::class, 'destroy'])->name('banks.destroy');
        Route::get('/banks/{bank}/edit', [BankAccountController::class, 'edit'])->name('banks.edit');
        Route::put('/banks/{bank}', [BankAccountController::class, 'update'])->name('banks.update');

        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/create', [TransferController::class, 'create'])->name('transfers.create');
        Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::post('/transfers/{entry}/cancel', [DocumentCancellationController::class, 'transfer'])->name('transfers.cancel');
    });

    // 9. Accounting Module
    Route::prefix('accounting')->name('accounting.')->group(function () {
        // Chart of Accounts
        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/create', [AccountController::class, 'create'])->name('accounts.create');
        Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])->name('accounts.edit');
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

        // Journal Entries
        Route::get('/journals', [JournalEntryController::class, 'index'])->name('journals.index');
        Route::post('/journals', [JournalEntryController::class, 'store'])->name('journals.store');
        Route::get('/journals/create', [JournalEntryController::class, 'create'])->name('journals.create');
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
        Route::get('/fiscal-years/create', [A\FiscalYearController::class, 'create'])->name('fiscal-years.create');
        Route::get('/fiscal-years/preview', [A\FiscalYearController::class, 'preview'])->name('fiscal-years.preview');
        Route::post('/fiscal-years', [A\FiscalYearController::class, 'store'])->name('fiscal-years.store');
        Route::put('/fiscal-periods/{period}', [A\FiscalYearController::class, 'updatePeriod'])->name('fiscal-periods.update');
        Route::get('/fiscal-years/{year}/close', [A\FiscalYearController::class, 'closeForm'])->name('fiscal-years.close-form');
        Route::post('/fiscal-years/{year}/close', [A\FiscalYearController::class, 'close'])->name('fiscal-years.close');
        Route::post('/fiscal-years/{year}/reopen', [A\FiscalYearController::class, 'reopen'])->name('fiscal-years.reopen');

        // Account mapping
        Route::get('/mappings', [A\AccountMappingController::class, 'index'])->name('mappings.index');
        Route::put('/mappings', [A\AccountMappingController::class, 'update'])->name('mappings.update');
        Route::put('/mappings/dimensions', [A\AccountMappingController::class, 'dimensions'])->name('mappings.dimensions');

        // Fixed assets
        Route::get('/period-end', [A\PeriodEndController::class, 'index'])->name('period-end.index');
        Route::post('/period-end/revalue', [A\PeriodEndController::class, 'revalue'])->name('period-end.revalue');
        Route::post('/period-end/zakat', [A\PeriodEndController::class, 'zakat'])->name('period-end.zakat');
        Route::get('/fixed-assets', [A\FixedAssetController::class, 'index'])->name('fixed-assets.index');
        Route::post('/fixed-assets', [A\FixedAssetController::class, 'store'])->name('fixed-assets.store');
        Route::post('/fixed-assets/categories', [A\FixedAssetController::class, 'storeCategory'])->name('fixed-assets.categories.store');
        Route::post('/fixed-assets/depreciate', [A\FixedAssetController::class, 'depreciate'])->name('fixed-assets.depreciate');
        Route::get('/fixed-assets/register', [A\FixedAssetController::class, 'register'])->name('fixed-assets.register');
        Route::get('/fixed-assets/{asset}', [A\FixedAssetController::class, 'show'])->name('fixed-assets.show');
        Route::post('/fixed-assets/{asset}/dispose', [A\FixedAssetController::class, 'dispose'])->name('fixed-assets.dispose');
        Route::post('/fixed-assets/{asset}/usage', [A\FixedAssetController::class, 'storeUsage'])->name('fixed-assets.usage');
        Route::post('/fixed-assets/{asset}/impair', [A\FixedAssetController::class, 'impair'])->name('fixed-assets.impair');

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
        Route::post('/reports/vat-return/settle', [A\FinancialReportController::class, 'settleVat'])->name('reports.vat-return.settle');
        Route::delete('/reports/vat-settlements/{settlement}', [A\FinancialReportController::class, 'cancelVatSettlement'])->name('reports.vat-settlements.cancel');

        // General Ledger
        Route::get('/ledger', [GeneralLedgerController::class, 'index'])->name('ledger.index');

        // Trial Balance
        Route::get('/trial-balance', [TrialBalanceController::class, 'index'])->name('trial-balance.index');
    });

    // 10. Reports Module
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/sales', [ReportController::class, 'sales'])->name('sales');
        Route::get('/insights', [InsightsController::class, 'index'])->name('insights');
        Route::get('/inventory', [ReportController::class, 'inventory'])->name('inventory');
        Route::get('/financial', [ReportController::class, 'financial'])->name('financial');
        Route::get('/vat', [ReportController::class, 'vat'])->name('vat');
    });

    // 11. Users & Roles
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
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
        Route::post('/holidays', [HrSettingsController::class, 'storeHoliday'])->name('holidays.store');
        Route::delete('/holidays/{holiday}', [HrSettingsController::class, 'destroyHoliday'])->name('holidays.destroy');

        Route::get('/nationalities', [NationalityController::class, 'index'])->name('nationalities.index');
        Route::get('/nationalities/create', [NationalityController::class, 'create'])->name('nationalities.create');
        Route::post('/nationalities', [NationalityController::class, 'store'])->name('nationalities.store');
        Route::get('/nationalities/{nationality}/edit', [NationalityController::class, 'edit'])->name('nationalities.edit');
        Route::put('/nationalities/{nationality}', [NationalityController::class, 'update'])->name('nationalities.update');

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
        Route::post('/payroll/{payroll}/unapprove', [PayrollController::class, 'unapprove'])->name('payroll.unapprove');
        Route::post('/payroll/{payroll}/pay', [PayrollController::class, 'pay'])->name('payroll.pay');
        Route::post('/payroll/{payroll}/adjustments', [PayrollController::class, 'storeAdjustment'])->name('payroll.adjustments.store');
        Route::delete('/payroll/{payroll}/adjustments/{adjustment}', [PayrollController::class, 'destroyAdjustment'])->name('payroll.adjustments.destroy');
        Route::get('/payroll/{payroll}/wps', [PayrollController::class, 'wps'])->name('payroll.wps');
        Route::get('/reports/gosi', [HrReportController::class, 'gosi'])->name('reports.gosi');
        Route::get('/reports/saudization', [HrReportController::class, 'saudization'])->name('reports.saudization');
        Route::get('/payroll/{payroll}/payslips/{item}', [PayrollController::class, 'payslip'])->name('payroll.payslip');

        Route::get('/me', [SelfServiceController::class, 'index'])->name('me');
        Route::post('/me/leaves', [SelfServiceController::class, 'requestLeave'])->name('me.leaves');
        Route::post('/me/loans', [SelfServiceController::class, 'requestLoan'])->name('me.loans');
        Route::post('/me/leaves/{leave}/cancel', [SelfServiceController::class, 'cancelLeave'])->name('me.leaves.cancel');
        Route::post('/me/loans/{loan}/cancel', [SelfServiceController::class, 'cancelLoan'])->name('me.loans.cancel');
        Route::get('/me/payslips/{item}', [SelfServiceController::class, 'payslip'])->name('me.payslip');
    });

    // 13. Branches, Templates, Settings
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::post('/branches/tax-rates', [BranchController::class, 'taxRates'])->name('branches.tax-rates');
    Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');

    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('/templates/create', [TemplateController::class, 'create'])->name('templates.create');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::get('/templates/{template}/designer', [TemplateController::class, 'designer'])->name('templates.designer');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::post('/templates/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('templates.duplicate');
    Route::post('/templates/{template}/default', [TemplateController::class, 'setDefault'])->name('templates.default');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
    Route::get('/templates/preview-invoice/{invoice}', [TemplateController::class, 'getInvoicePreview'])->name('templates.preview-invoice');
    Route::post('/templates/images', [FreeformTemplateController::class, 'upload'])->name('templates.images');
    Route::get('/templates/fonts/{font}', [FreeformTemplateController::class, 'font'])->name('templates.fonts');
    Route::get('/templates/images/{file}', [FreeformTemplateController::class, 'image'])->name('templates.image');
    Route::get('/templates/{template}/data', [FreeformTemplateController::class, 'data'])->name('templates.data');
    Route::post('/templates/{template}/preview', [FreeformTemplateController::class, 'preview'])->name('templates.preview');
    Route::get('/templates/{template}/preset', [FreeformTemplateController::class, 'preset'])->name('templates.preset');

    Route::get('/settings', [CompanySettingController::class, 'index'])->name('settings.index');
    Route::get('/settings/currencies', [CurrencyController::class, 'index'])->name('settings.currencies');
    Route::get('/settings/modules', [ModuleSettingsController::class, 'index'])->name('settings.modules');
    Route::put('/settings/modules', [ModuleSettingsController::class, 'update'])->name('settings.modules.update');
    Route::post('/settings/currencies', [CurrencyController::class, 'store'])->name('settings.currencies.store');
    Route::post('/settings/currencies/rates', [CurrencyController::class, 'storeRate'])->name('settings.currencies.rates');
    Route::delete('/settings/currencies/{currency}', [CurrencyController::class, 'destroy'])->name('settings.currencies.destroy');
    Route::put('/settings', [CompanySettingController::class, 'update'])->name('settings.update');
    Route::post('/settings/logo', [CompanySettingController::class, 'uploadLogo'])->name('settings.logo.upload');
    Route::delete('/settings/logo', [CompanySettingController::class, 'deleteLogo'])->name('settings.logo.delete');
    Route::post('/settings/zatca/onboard', [CompanySettingController::class, 'onboardZatca'])->name('settings.zatca.onboard');

    // Subscription, integrations, notifications, data transfer
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoices.show');
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

    // The idle warning's «متابعة الجلسة» (any request counts as activity).
    Route::post('/session/keep-alive', fn () => response()->noContent())->name('session.keep-alive');
    Route::get('/recycle-bin', [RecycleBinController::class, 'index'])->name('recycle-bin.index');
    Route::post('/recycle-bin/{type}/{id}/restore', [RecycleBinController::class, 'restore'])->name('recycle-bin.restore');
    Route::delete('/recycle-bin/{type}/{id}', [RecycleBinController::class, 'purge'])->name('recycle-bin.purge');
    Route::get('/whats-new', [ReleaseNotesController::class, 'index'])->name('whats-new.index');
    Route::get('/feature-requests', [FeatureRequestController::class, 'index'])->name('feature-requests.index');

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

    // Manufacturing: work centres, BOMs, routings, production orders, process costing, MRP, quality, subcontracting
    Route::prefix('manufacturing')->name('manufacturing.')->group(function () {
        Route::get('/', [Mfg\DashboardController::class, 'index'])->name('dashboard');

        Route::get('/work-centers', [Mfg\WorkCenterController::class, 'index'])->name('work-centers.index');
        Route::get('/work-centers/create', [Mfg\WorkCenterController::class, 'create'])->name('work-centers.create');
        Route::post('/work-centers', [Mfg\WorkCenterController::class, 'store'])->name('work-centers.store');
        Route::get('/work-centers/{workCenter}/edit', [Mfg\WorkCenterController::class, 'edit'])->name('work-centers.edit');
        Route::put('/work-centers/{workCenter}', [Mfg\WorkCenterController::class, 'update'])->name('work-centers.update');
        Route::delete('/work-centers/{workCenter}', [Mfg\WorkCenterController::class, 'destroy'])->name('work-centers.destroy');

        Route::get('/boms', [Mfg\BomController::class, 'index'])->name('boms.index');
        Route::get('/boms/create', [Mfg\BomController::class, 'create'])->name('boms.create');
        Route::post('/boms', [Mfg\BomController::class, 'store'])->name('boms.store');
        Route::get('/boms/{bom}/edit', [Mfg\BomController::class, 'edit'])->name('boms.edit');
        Route::put('/boms/{bom}', [Mfg\BomController::class, 'update'])->name('boms.update');
        Route::delete('/boms/{bom}', [Mfg\BomController::class, 'destroy'])->name('boms.destroy');

        Route::get('/routings', [Mfg\RoutingController::class, 'index'])->name('routings.index');
        Route::get('/routings/create', [Mfg\RoutingController::class, 'create'])->name('routings.create');
        Route::post('/routings', [Mfg\RoutingController::class, 'store'])->name('routings.store');
        Route::get('/routings/{routing}/edit', [Mfg\RoutingController::class, 'edit'])->name('routings.edit');
        Route::put('/routings/{routing}', [Mfg\RoutingController::class, 'update'])->name('routings.update');
        Route::delete('/routings/{routing}', [Mfg\RoutingController::class, 'destroy'])->name('routings.destroy');

        Route::get('/orders', [Mfg\ProductionOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/create', [Mfg\ProductionOrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [Mfg\ProductionOrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [Mfg\ProductionOrderController::class, 'show'])->name('orders.show');
        Route::get('/orders/{order}/print', [Mfg\ProductionOrderController::class, 'print'])->name('orders.print');
        Route::post('/orders/{order}/release', [Mfg\ProductionOrderController::class, 'release'])->name('orders.release');
        Route::post('/orders/{order}/issue', [Mfg\ProductionOrderController::class, 'issue'])->name('orders.issue');
        Route::post('/orders/{order}/issue-all', [Mfg\ProductionOrderController::class, 'issueAll'])->name('orders.issue-all');
        Route::post('/orders/{order}/return', [Mfg\ProductionOrderController::class, 'return'])->name('orders.return');
        Route::post('/orders/{order}/time', [Mfg\ProductionOrderController::class, 'time'])->name('orders.time');
        Route::post('/orders/{order}/receive', [Mfg\ProductionOrderController::class, 'receive'])->name('orders.receive');
        Route::post('/orders/{order}/close', [Mfg\ProductionOrderController::class, 'close'])->name('orders.close');
        Route::post('/orders/{order}/cancel', [Mfg\ProductionOrderController::class, 'cancel'])->name('orders.cancel');
        Route::delete('/orders/{order}', [Mfg\ProductionOrderController::class, 'destroy'])->name('orders.destroy');

        Route::get('/shop-floor', [Mfg\ShopFloorController::class, 'index'])->name('shop-floor.index');
        Route::post('/shop-floor/log', [Mfg\ShopFloorController::class, 'log'])->name('shop-floor.log');

        Route::get('/process/stages', [Mfg\ProcessStageController::class, 'index'])->name('stages.index');
        Route::get('/process/stages/create', [Mfg\ProcessStageController::class, 'create'])->name('stages.create');
        Route::post('/process/stages', [Mfg\ProcessStageController::class, 'store'])->name('stages.store');
        Route::get('/process/stages/{stage}/edit', [Mfg\ProcessStageController::class, 'edit'])->name('stages.edit');
        Route::put('/process/stages/{stage}', [Mfg\ProcessStageController::class, 'update'])->name('stages.update');
        Route::delete('/process/stages/{stage}', [Mfg\ProcessStageController::class, 'destroy'])->name('stages.destroy');
        Route::get('/process/periods', [Mfg\ProcessPeriodController::class, 'index'])->name('periods.index');
        Route::get('/process/periods/create', [Mfg\ProcessPeriodController::class, 'create'])->name('periods.create');
        Route::post('/process/periods', [Mfg\ProcessPeriodController::class, 'store'])->name('periods.store');
        Route::get('/process/periods/{period}', [Mfg\ProcessPeriodController::class, 'show'])->name('periods.show');
        Route::post('/process/periods/{period}/issue', [Mfg\ProcessPeriodController::class, 'issue'])->name('periods.issue');
        Route::post('/process/periods/{period}/conversion', [Mfg\ProcessPeriodController::class, 'conversion'])->name('periods.conversion');
        Route::post('/process/periods/{period}/close', [Mfg\ProcessPeriodController::class, 'close'])->name('periods.close');

        Route::get('/mrp', [Mfg\MrpController::class, 'index'])->name('mrp.index');
        Route::post('/mrp/run', [Mfg\MrpController::class, 'run'])->name('mrp.run');
        Route::post('/mrp/convert-purchase', [Mfg\MrpController::class, 'convertPurchase'])->name('mrp.convert-purchase');
        Route::post('/mrp/{suggestion}/convert-production', [Mfg\MrpController::class, 'convertProduction'])->name('mrp.convert-production');
        Route::post('/mrp/{suggestion}/dismiss', [Mfg\MrpController::class, 'dismiss'])->name('mrp.dismiss');
        Route::get('/capacity', [Mfg\MrpController::class, 'capacity'])->name('capacity.index');

        Route::get('/quality/plans', [Mfg\QcPlanController::class, 'index'])->name('plans.index');
        Route::get('/quality/plans/create', [Mfg\QcPlanController::class, 'create'])->name('plans.create');
        Route::post('/quality/plans', [Mfg\QcPlanController::class, 'store'])->name('plans.store');
        Route::get('/quality/plans/{plan}/edit', [Mfg\QcPlanController::class, 'edit'])->name('plans.edit');
        Route::put('/quality/plans/{plan}', [Mfg\QcPlanController::class, 'update'])->name('plans.update');
        Route::delete('/quality/plans/{plan}', [Mfg\QcPlanController::class, 'destroy'])->name('plans.destroy');
        Route::get('/quality/inspections', [Mfg\QcInspectionController::class, 'index'])->name('inspections.index');
        Route::get('/quality/inspections/create', [Mfg\QcInspectionController::class, 'create'])->name('inspections.create');
        Route::post('/quality/inspections', [Mfg\QcInspectionController::class, 'store'])->name('inspections.store');
        Route::get('/quality/inspections/{inspection}', [Mfg\QcInspectionController::class, 'show'])->name('inspections.show');
        Route::post('/quality/inspections/{inspection}/complete', [Mfg\QcInspectionController::class, 'complete'])->name('inspections.complete');

        Route::get('/subcontracts', [Mfg\SubcontractController::class, 'index'])->name('subcontracts.index');
        Route::get('/subcontracts/create', [Mfg\SubcontractController::class, 'create'])->name('subcontracts.create');
        Route::post('/subcontracts', [Mfg\SubcontractController::class, 'store'])->name('subcontracts.store');
        Route::get('/subcontracts/{order}', [Mfg\SubcontractController::class, 'show'])->name('subcontracts.show');
        Route::post('/subcontracts/{order}/send', [Mfg\SubcontractController::class, 'send'])->name('subcontracts.send');
        Route::post('/subcontracts/{order}/receive', [Mfg\SubcontractController::class, 'receive'])->name('subcontracts.receive');
        Route::post('/subcontracts/{order}/cancel', [Mfg\SubcontractController::class, 'cancel'])->name('subcontracts.cancel');

        Route::get('/reports', [Mfg\ReportController::class, 'index'])->name('reports.index');
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
        Route::post('/documents/submit-pending', [ZatcaController::class, 'submitPending'])->name('documents.submit-pending');
        Route::post('/documents/{document}/resubmit', [ZatcaController::class, 'resubmit'])->name('documents.resubmit');
        Route::get('/documents/{document}/xml', [ZatcaController::class, 'xml'])->name('documents.xml');
    });
});

require __DIR__.'/auth.php';
