<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Http\Controllers\SuperAdmin\SuperAdminDashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Home redirect
Route::get('/', function () {
    if (auth()->check()) {
        if (auth()->user()->isSuperAdmin() && !app(\App\Services\TenantManager::class)->isImpersonating()) {
            return redirect()->route('super-admin.dashboard');
        }
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

// Localization Switcher
Route::post('/locale/switch', [LocaleController::class, 'switch'])->name('locale.switch');

// Google OAuth
Route::get('/auth/google', [GoogleAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// Authenticated ERP Routes
Route::middleware(['auth'])->group(function () {
    // 1. Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // 2. Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // 3. Super Admin Routes
    Route::prefix('super-admin')->name('super-admin.')->group(function () {
        // Leave Impersonation route - requires auth + must be actually impersonating
        Route::post('/impersonate/leave', [ImpersonationController::class, 'leave'])
            ->name('impersonate.leave')
            ->middleware('super_admin');

        Route::middleware(['super_admin'])->group(function () {
            Route::get('/dashboard', [SuperAdminDashboardController::class, 'index'])->name('dashboard');
            Route::post('/impersonate/{company}', [ImpersonationController::class, 'enter'])->name('impersonate.enter');
        });
    });

    // 4. Sales Module
    Route::prefix('sales')->name('sales.')->group(function () {
        // Invoices
        Route::get('/invoices', [\App\Http\Controllers\InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/create', [\App\Http\Controllers\InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoices', [\App\Http\Controllers\InvoiceController::class, 'store'])
            ->name('invoices.store')
            ->middleware('throttle:invoices');
        Route::get('/invoices/{invoice}', [\App\Http\Controllers\InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/print', [\App\Http\Controllers\InvoiceController::class, 'print'])->name('invoices.print');
        Route::delete('/invoices/{invoice}', [\App\Http\Controllers\InvoiceController::class, 'destroy'])->name('invoices.destroy');

        // Quotations
        Route::get('/quotations', [\App\Http\Controllers\QuotationController::class, 'index'])->name('quotations.index');
        Route::get('/quotations/create', [\App\Http\Controllers\QuotationController::class, 'create'])->name('quotations.create');
        Route::post('/quotations', [\App\Http\Controllers\QuotationController::class, 'store'])->name('quotations.store');
        Route::get('/quotations/{quotation}', [\App\Http\Controllers\QuotationController::class, 'show'])->name('quotations.show');
        Route::post('/quotations/{quotation}/convert', [\App\Http\Controllers\QuotationController::class, 'convertToInvoice'])->name('quotations.convert');

        // Clients (Customers)
        Route::get('/clients', [\App\Http\Controllers\CustomerController::class, 'index'])->name('clients.index');
        Route::get('/clients/create', [\App\Http\Controllers\CustomerController::class, 'create'])->name('clients.create');
        Route::post('/clients', [\App\Http\Controllers\CustomerController::class, 'store'])->name('clients.store');
        Route::post('/clients/upload-attachment', [\App\Http\Controllers\CustomerController::class, 'uploadAttachment'])->name('clients.upload-attachment');
        Route::get('/clients/{client}', [\App\Http\Controllers\CustomerController::class, 'show'])->name('clients.show');
        Route::get('/clients/{client}/statement', [\App\Http\Controllers\CustomerController::class, 'statement'])->name('clients.statement');
        Route::get('/clients/{client}/edit', [\App\Http\Controllers\CustomerController::class, 'edit'])->name('clients.edit');
        Route::put('/clients/{client}', [\App\Http\Controllers\CustomerController::class, 'update'])->name('clients.update');
        Route::delete('/clients/{client}', [\App\Http\Controllers\CustomerController::class, 'destroy'])->name('clients.destroy');

        // Returns & Payments
        Route::get('/returns', [\App\Http\Controllers\CreditNoteController::class, 'index'])->name('returns.index');
        Route::get('/returns/create', [\App\Http\Controllers\CreditNoteController::class, 'create'])->name('returns.create');
        Route::post('/returns', [\App\Http\Controllers\CreditNoteController::class, 'store'])->name('returns.store');
        Route::get('/returns/{creditNote}', [\App\Http\Controllers\CreditNoteController::class, 'show'])->name('returns.show');
        Route::get('/payments', [\App\Http\Controllers\CustomerPaymentController::class, 'index'])->name('payments.index');
        Route::post('/payments', [\App\Http\Controllers\CustomerPaymentController::class, 'store'])->name('payments.store');
    });

    // 5. POS Module
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [\App\Http\Controllers\PosController::class, 'index'])->name('index');
        Route::post('/session/open', [\App\Http\Controllers\PosController::class, 'openSession'])->name('session.open');
        Route::post('/session/{session}/close', [\App\Http\Controllers\PosController::class, 'closeSession'])->name('session.close');
        Route::post('/order', [\App\Http\Controllers\PosController::class, 'processOrder'])
            ->name('order.process')
            ->middleware('throttle:pos-orders');
        Route::get('/history', [\App\Http\Controllers\PosController::class, 'history'])->name('history');
    });

    // 6. Inventory Module
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/products', [\App\Http\Controllers\ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [\App\Http\Controllers\ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [\App\Http\Controllers\ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [\App\Http\Controllers\ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [\App\Http\Controllers\ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [\App\Http\Controllers\ProductController::class, 'destroy'])->name('products.destroy');

        Route::get('/categories', [\App\Http\Controllers\CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [\App\Http\Controllers\CategoryController::class, 'store'])->name('categories.store');
        Route::delete('/categories/{category}', [\App\Http\Controllers\CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::get('/operations', [\App\Http\Controllers\InventoryOperationController::class, 'index'])->name('operations.index');
        Route::post('/operations/transfer', [\App\Http\Controllers\InventoryOperationController::class, 'transfer'])->name('operations.transfer');
        Route::post('/operations/adjust', [\App\Http\Controllers\InventoryOperationController::class, 'adjust'])->name('operations.adjust');
        Route::get('/warehouses', [\App\Http\Controllers\WarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('/warehouses', [\App\Http\Controllers\WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('/warehouses/{warehouse}', [\App\Http\Controllers\WarehouseController::class, 'update'])->name('warehouses.update');
        Route::delete('/warehouses/{warehouse}', [\App\Http\Controllers\WarehouseController::class, 'destroy'])->name('warehouses.destroy');
        Route::get('/counts', [\App\Http\Controllers\StockCountController::class, 'index'])->name('counts.index');
        Route::post('/counts/reconcile', [\App\Http\Controllers\StockCountController::class, 'reconcile'])->name('counts.reconcile');
    });

    // 7. Purchases Module
    Route::prefix('purchases')->name('purchases.')->group(function () {
        // Suppliers
        Route::get('/suppliers', [\App\Http\Controllers\SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [\App\Http\Controllers\SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('/suppliers/{supplier}', [\App\Http\Controllers\SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [\App\Http\Controllers\SupplierController::class, 'destroy'])->name('suppliers.destroy');

        // Purchase Invoices
        Route::get('/invoices', [\App\Http\Controllers\PurchaseInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/create', [\App\Http\Controllers\PurchaseInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoices', [\App\Http\Controllers\PurchaseInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoices/{invoice}', [\App\Http\Controllers\PurchaseInvoiceController::class, 'show'])->name('invoices.show');
        Route::delete('/invoices/{invoice}', [\App\Http\Controllers\PurchaseInvoiceController::class, 'destroy'])->name('invoices.destroy');

        // Purchase Orders
        Route::get('/orders', [\App\Http\Controllers\PurchaseOrderController::class, 'index'])->name('orders.index');
        Route::post('/orders/{order}/convert', [\App\Http\Controllers\PurchaseOrderController::class, 'convertToInvoice'])->name('orders.convert');

        // Supplier Payments
        Route::get('/payments', [\App\Http\Controllers\SupplierPaymentController::class, 'index'])->name('payments.index');
        Route::post('/payments', [\App\Http\Controllers\SupplierPaymentController::class, 'store'])->name('payments.store');
    });

    // 8. Finance Module
    Route::prefix('finance')->name('finance.')->group(function () {
        // Expenses
        Route::get('/expenses', [\App\Http\Controllers\Finance\ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('/expenses', [\App\Http\Controllers\Finance\ExpenseController::class, 'store'])->name('expenses.store');
        Route::delete('/expenses/{expense}', [\App\Http\Controllers\Finance\ExpenseController::class, 'destroy'])->name('expenses.destroy');

        // Revenues
        Route::get('/revenues', [\App\Http\Controllers\Finance\RevenueController::class, 'index'])->name('revenues.index');
        Route::post('/revenues', [\App\Http\Controllers\Finance\RevenueController::class, 'store'])->name('revenues.store');
        Route::delete('/revenues/{revenue}', [\App\Http\Controllers\Finance\RevenueController::class, 'destroy'])->name('revenues.destroy');

        // Banks & Treasuries
        Route::get('/banks', [\App\Http\Controllers\Finance\BankAccountController::class, 'index'])->name('banks.index');
        Route::post('/banks', [\App\Http\Controllers\Finance\BankAccountController::class, 'store'])->name('banks.store');
        Route::delete('/banks/{bank}', [\App\Http\Controllers\Finance\BankAccountController::class, 'destroy'])->name('banks.destroy');

        Route::get('/transfers', [\App\Http\Controllers\Finance\TransferController::class, 'index'])->name('transfers.index');
        Route::post('/transfers', [\App\Http\Controllers\Finance\TransferController::class, 'store'])->name('transfers.store');
    });

    // 9. Accounting Module
    Route::prefix('accounting')->name('accounting.')->group(function () {
        // Chart of Accounts
        Route::get('/accounts', [\App\Http\Controllers\Accounting\AccountController::class, 'index'])->name('accounts.index');
        Route::post('/accounts', [\App\Http\Controllers\Accounting\AccountController::class, 'store'])->name('accounts.store');
        Route::put('/accounts/{account}', [\App\Http\Controllers\Accounting\AccountController::class, 'update'])->name('accounts.update');
        Route::delete('/accounts/{account}', [\App\Http\Controllers\Accounting\AccountController::class, 'destroy'])->name('accounts.destroy');

        // Journal Entries
        Route::get('/journals', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'index'])->name('journals.index');
        Route::post('/journals', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'store'])->name('journals.store');
        Route::get('/journals/{entry}', [\App\Http\Controllers\Accounting\JournalEntryController::class, 'show'])->name('journals.show');

        // General Ledger
        Route::get('/ledger', [\App\Http\Controllers\Accounting\GeneralLedgerController::class, 'index'])->name('ledger.index');

        // Trial Balance
        Route::get('/trial-balance', [\App\Http\Controllers\Accounting\TrialBalanceController::class, 'index'])->name('trial-balance.index');
    });

    // 10. Reports Module
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/sales', [\App\Http\Controllers\Reports\ReportController::class, 'sales'])->name('sales');
        Route::get('/inventory', [\App\Http\Controllers\Reports\ReportController::class, 'inventory'])->name('inventory');
        Route::get('/financial', [\App\Http\Controllers\Reports\ReportController::class, 'financial'])->name('financial');
        Route::get('/vat', [\App\Http\Controllers\Reports\ReportController::class, 'vat'])->name('vat');
    });

    // 11. Users & Roles
    Route::get('/users', [\App\Http\Controllers\UserController::class, 'index'])->name('users.index');
    Route::post('/users', [\App\Http\Controllers\UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [\App\Http\Controllers\UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [\App\Http\Controllers\UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/roles', [\App\Http\Controllers\RoleController::class, 'index'])->name('roles.index');
    Route::post('/roles', [\App\Http\Controllers\RoleController::class, 'store'])->name('roles.store');
    Route::delete('/roles/{role}', [\App\Http\Controllers\RoleController::class, 'destroy'])->name('roles.destroy');

    // 12. HR Module
    Route::prefix('hr')->name('hr.')->group(function () {
        Route::get('/employees', [\App\Http\Controllers\HR\EmployeeController::class, 'index'])->name('employees.index');
        Route::post('/employees', [\App\Http\Controllers\HR\EmployeeController::class, 'store'])->name('employees.store');
        Route::delete('/employees/{employee}', [\App\Http\Controllers\HR\EmployeeController::class, 'destroy'])->name('employees.destroy');

        Route::get('/payroll', [\App\Http\Controllers\HR\PayrollController::class, 'index'])->name('payroll.index');
        Route::post('/payroll', [\App\Http\Controllers\HR\PayrollController::class, 'store'])->name('payroll.store');
    });

    // 13. Branches, Templates, Settings
    Route::get('/branches', [\App\Http\Controllers\BranchController::class, 'index'])->name('branches.index');
    Route::post('/branches', [\App\Http\Controllers\BranchController::class, 'store'])->name('branches.store');
    Route::delete('/branches/{branch}', [\App\Http\Controllers\BranchController::class, 'destroy'])->name('branches.destroy');

    Route::get('/templates', [\App\Http\Controllers\TemplateController::class, 'index'])->name('templates.index');
    Route::post('/templates', [\App\Http\Controllers\TemplateController::class, 'store'])->name('templates.store');
    Route::get('/templates/{template}/designer', [\App\Http\Controllers\TemplateController::class, 'designer'])->name('templates.designer');
    Route::put('/templates/{template}', [\App\Http\Controllers\TemplateController::class, 'update'])->name('templates.update');
    Route::post('/templates/{template}/duplicate', [\App\Http\Controllers\TemplateController::class, 'duplicate'])->name('templates.duplicate');
    Route::post('/templates/{template}/default', [\App\Http\Controllers\TemplateController::class, 'setDefault'])->name('templates.default');
    Route::delete('/templates/{template}', [\App\Http\Controllers\TemplateController::class, 'destroy'])->name('templates.destroy');
    Route::get('/templates/preview-invoice/{invoice}', [\App\Http\Controllers\TemplateController::class, 'getInvoicePreview'])->name('templates.preview-invoice');

    Route::get('/settings', [\App\Http\Controllers\CompanySettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [\App\Http\Controllers\CompanySettingController::class, 'update'])->name('settings.update');
    Route::post('/settings/logo', [\App\Http\Controllers\CompanySettingController::class, 'uploadLogo'])->name('settings.logo.upload');
    Route::delete('/settings/logo', [\App\Http\Controllers\CompanySettingController::class, 'deleteLogo'])->name('settings.logo.delete');
    Route::post('/settings/zatca/onboard', [\App\Http\Controllers\CompanySettingController::class, 'onboardZatca'])->name('settings.zatca.onboard');
});

require __DIR__.'/auth.php';

