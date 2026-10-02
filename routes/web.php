<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Finance\BankAccountController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Finance\RevenueController;
use App\Http\Controllers\Finance\TransferController;
use App\Http\Controllers\HR\EmployeeController;
use App\Http\Controllers\HR\PayrollController;
use App\Http\Controllers\InventoryOperationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Http\Controllers\SuperAdmin\SuperAdminDashboardController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
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

});

// Company (tenant) ERP routes: authenticated user + resolved tenant required.
Route::middleware(['auth', 'tenant', 'erp.authorize', 'throttle:tenant-web'])->group(function () {
    // 1. Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Type-ahead lookups for document forms (bounded results).
    Route::prefix('lookup')->name('lookup.')->group(function () {
        Route::get('/customers', [LookupController::class, 'customers'])->name('customers');
        Route::get('/products', [LookupController::class, 'products'])->name('products');
        Route::get('/suppliers', [LookupController::class, 'suppliers'])->name('suppliers');
    });

    // Private tenant files (attachments) — streamed only to the owning company.
    Route::get('/files/{token}', [FileController::class, 'show'])
        ->where('token', '.*')
        ->name('files.show');

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
        Route::post('/counts/reconcile', [StockCountController::class, 'reconcile'])->name('counts.reconcile');
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
        Route::post('/invoices', [PurchaseInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoices/{invoice}', [PurchaseInvoiceController::class, 'show'])->name('invoices.show');
        Route::delete('/invoices/{invoice}', [PurchaseInvoiceController::class, 'destroy'])->name('invoices.destroy');

        // Purchase Orders
        Route::get('/orders', [PurchaseOrderController::class, 'index'])->name('orders.index');
        Route::post('/orders/{order}/convert', [PurchaseOrderController::class, 'convertToInvoice'])->name('orders.convert');

        // Supplier Payments
        Route::get('/payments', [SupplierPaymentController::class, 'index'])->name('payments.index');
        Route::post('/payments', [SupplierPaymentController::class, 'store'])->name('payments.store');
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

    // 12. HR Module
    Route::prefix('hr')->name('hr.')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');

        Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
        Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store');
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
});

require __DIR__.'/auth.php';
