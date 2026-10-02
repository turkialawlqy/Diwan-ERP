<?php

/*
|--------------------------------------------------------------------------
| ERP Permission Registry
|--------------------------------------------------------------------------
|
| Single source of truth for every permission in the system. Permission names
| are "{module}.{resource}.{action}". `php artisan erp:sync-permissions`
| creates them and refreshes each company's default roles.
|
*/

return [

    'actions' => [
        'view' => ['ar' => 'عرض', 'en' => 'View'],
        'create' => ['ar' => 'إضافة', 'en' => 'Create'],
        'edit' => ['ar' => 'تعديل', 'en' => 'Edit'],
        'delete' => ['ar' => 'حذف', 'en' => 'Delete'],
        'post' => ['ar' => 'ترحيل', 'en' => 'Post'],
        'approve' => ['ar' => 'اعتماد', 'en' => 'Approve'],
        'cancel' => ['ar' => 'إلغاء', 'en' => 'Cancel'],
        'print' => ['ar' => 'طباعة', 'en' => 'Print'],
        'export' => ['ar' => 'تصدير', 'en' => 'Export'],
        'convert' => ['ar' => 'تحويل', 'en' => 'Convert'],
        'use' => ['ar' => 'استخدام', 'en' => 'Use'],
        'manage' => ['ar' => 'إدارة', 'en' => 'Manage'],
    ],

    'modules' => [

        'dashboard' => [
            'ar' => 'لوحة التحكم', 'en' => 'Dashboard',
            'resources' => [
                'main' => ['ar' => 'لوحة المعلومات', 'en' => 'Dashboard', 'actions' => ['view']],
            ],
        ],

        'sales' => [
            'ar' => 'المبيعات', 'en' => 'Sales',
            'resources' => [
                'invoices' => ['ar' => 'فواتير المبيعات', 'en' => 'Sales Invoices', 'actions' => ['view', 'create', 'edit', 'delete', 'post', 'cancel', 'print', 'export']],
                'quotations' => ['ar' => 'عروض الأسعار', 'en' => 'Quotations', 'actions' => ['view', 'create', 'edit', 'delete', 'convert', 'print']],
                'clients' => ['ar' => 'العملاء', 'en' => 'Customers', 'actions' => ['view', 'create', 'edit', 'delete', 'export']],
                'returns' => ['ar' => 'مرتجعات المبيعات', 'en' => 'Sales Returns', 'actions' => ['view', 'create', 'cancel', 'print']],
                'payments' => ['ar' => 'سندات القبض', 'en' => 'Customer Receipts', 'actions' => ['view', 'create', 'cancel', 'print']],
                'discounts' => ['ar' => 'الخصومات وتعديل الأسعار', 'en' => 'Discounts & Price Override', 'actions' => ['use', 'approve']],
            ],
        ],

        'pos' => [
            'ar' => 'نقاط البيع', 'en' => 'Point of Sale',
            'resources' => [
                'terminal' => ['ar' => 'شاشة البيع', 'en' => 'POS Terminal', 'actions' => ['use']],
                'sessions' => ['ar' => 'الورديات', 'en' => 'Sessions', 'actions' => ['create', 'edit', 'manage']],
                'history' => ['ar' => 'سجل الطلبات', 'en' => 'Order History', 'actions' => ['view']],
            ],
        ],

        'inventory' => [
            'ar' => 'المستودعات', 'en' => 'Inventory',
            'resources' => [
                'products' => ['ar' => 'الأصناف', 'en' => 'Products', 'actions' => ['view', 'create', 'edit', 'delete', 'export']],
                'costs' => ['ar' => 'تكلفة الأصناف', 'en' => 'Product Costs', 'actions' => ['view']],
                'categories' => ['ar' => 'التصنيفات', 'en' => 'Categories', 'actions' => ['view', 'create', 'edit', 'delete']],
                'warehouses' => ['ar' => 'المستودعات', 'en' => 'Warehouses', 'actions' => ['view', 'create', 'edit', 'delete']],
                'operations' => ['ar' => 'التحويلات والتسويات', 'en' => 'Transfers & Adjustments', 'actions' => ['view', 'create', 'approve']],
                'counts' => ['ar' => 'الجرد', 'en' => 'Stock Counts', 'actions' => ['view', 'create', 'approve']],
            ],
        ],

        'purchases' => [
            'ar' => 'المشتريات', 'en' => 'Purchases',
            'resources' => [
                'suppliers' => ['ar' => 'الموردون', 'en' => 'Suppliers', 'actions' => ['view', 'create', 'edit', 'delete', 'export']],
                'orders' => ['ar' => 'أوامر الشراء', 'en' => 'Purchase Orders', 'actions' => ['view', 'create', 'edit', 'approve', 'convert', 'print']],
                'invoices' => ['ar' => 'فواتير المشتريات', 'en' => 'Purchase Invoices', 'actions' => ['view', 'create', 'edit', 'delete', 'post', 'print']],
                'returns' => ['ar' => 'مرتجعات المشتريات', 'en' => 'Purchase Returns', 'actions' => ['view', 'create', 'print']],
                'payments' => ['ar' => 'سندات الصرف للموردين', 'en' => 'Supplier Payments', 'actions' => ['view', 'create', 'cancel', 'print']],
            ],
        ],

        'finance' => [
            'ar' => 'المالية', 'en' => 'Finance',
            'resources' => [
                'expenses' => ['ar' => 'المصروفات', 'en' => 'Expenses', 'actions' => ['view', 'create', 'delete']],
                'revenues' => ['ar' => 'الإيرادات', 'en' => 'Revenues', 'actions' => ['view', 'create', 'delete']],
                'banks' => ['ar' => 'البنوك والصناديق', 'en' => 'Banks & Cash', 'actions' => ['view', 'create', 'edit', 'delete']],
                'transfers' => ['ar' => 'التحويلات المالية', 'en' => 'Transfers', 'actions' => ['view', 'create']],
            ],
        ],

        'accounting' => [
            'ar' => 'المحاسبة', 'en' => 'Accounting',
            'resources' => [
                'accounts' => ['ar' => 'دليل الحسابات', 'en' => 'Chart of Accounts', 'actions' => ['view', 'create', 'edit', 'delete']],
                'journals' => ['ar' => 'القيود اليومية', 'en' => 'Journal Entries', 'actions' => ['view', 'create', 'edit', 'post', 'cancel', 'print']],
                'ledger' => ['ar' => 'الأستاذ العام', 'en' => 'General Ledger', 'actions' => ['view', 'export']],
                'trial_balance' => ['ar' => 'ميزان المراجعة', 'en' => 'Trial Balance', 'actions' => ['view', 'export']],
            ],
        ],

        'hr' => [
            'ar' => 'شؤون الموظفين', 'en' => 'Human Resources',
            'resources' => [
                'employees' => ['ar' => 'الموظفون', 'en' => 'Employees', 'actions' => ['view', 'create', 'edit', 'delete']],
                'payroll' => ['ar' => 'مسيرات الرواتب', 'en' => 'Payroll', 'actions' => ['view', 'create', 'approve', 'post']],
                'salaries' => ['ar' => 'رواتب الموظفين', 'en' => 'Salary Details', 'actions' => ['view']],
            ],
        ],

        'reports' => [
            'ar' => 'التقارير', 'en' => 'Reports',
            'resources' => [
                'sales' => ['ar' => 'تقارير المبيعات', 'en' => 'Sales Reports', 'actions' => ['view', 'export']],
                'inventory' => ['ar' => 'تقارير المخزون', 'en' => 'Inventory Reports', 'actions' => ['view', 'export']],
                'financial' => ['ar' => 'التقارير المالية', 'en' => 'Financial Reports', 'actions' => ['view', 'export']],
                'vat' => ['ar' => 'تقارير الضريبة', 'en' => 'VAT Reports', 'actions' => ['view', 'export']],
            ],
        ],

        'admin' => [
            'ar' => 'الإدارة والإعدادات', 'en' => 'Administration',
            'resources' => [
                'users' => ['ar' => 'المستخدمون', 'en' => 'Users', 'actions' => ['view', 'create', 'edit', 'delete']],
                'roles' => ['ar' => 'الأدوار والصلاحيات', 'en' => 'Roles & Permissions', 'actions' => ['view', 'create', 'edit', 'delete']],
                'branches' => ['ar' => 'الفروع', 'en' => 'Branches', 'actions' => ['view', 'create', 'edit', 'delete']],
                'templates' => ['ar' => 'قوالب الطباعة', 'en' => 'Print Templates', 'actions' => ['view', 'create', 'edit', 'delete']],
                'settings' => ['ar' => 'إعدادات المنشأة', 'en' => 'Company Settings', 'actions' => ['view', 'edit']],
                'zatca' => ['ar' => 'الربط مع هيئة الزكاة', 'en' => 'ZATCA Integration', 'actions' => ['manage']],
                'audit' => ['ar' => 'سجل التدقيق', 'en' => 'Audit Log', 'actions' => ['view']],
                'data_scope' => ['ar' => 'الوصول لكل الفروع', 'en' => 'Access All Branches', 'actions' => ['manage']],
            ],
        ],

    ],

    /*
    | Owner role: full access, cannot be deleted or edited, always kept on at
    | least one user.
    */
    'owner_role' => 'admin',

    /*
    | Default roles seeded for every company. A value of '*' grants everything;
    | otherwise a list of permission names or "module.*" / "module.resource.*" wildcards.
    */
    'default_roles' => [
        'admin' => ['ar' => 'مدير المنشأة', 'en' => 'Owner / Administrator', 'permissions' => '*'],
        'accountant' => [
            'ar' => 'محاسب', 'en' => 'Accountant',
            'permissions' => ['dashboard.*', 'accounting.*', 'finance.*', 'reports.*', 'sales.invoices.view', 'sales.invoices.print', 'sales.clients.view', 'sales.payments.*', 'sales.returns.view', 'purchases.invoices.view', 'purchases.suppliers.view', 'purchases.payments.*', 'hr.payroll.view', 'hr.payroll.post', 'inventory.costs.view', 'inventory.products.view'],
        ],
        'sales' => [
            'ar' => 'مندوب مبيعات', 'en' => 'Sales',
            'permissions' => ['dashboard.*', 'sales.invoices.view', 'sales.invoices.create', 'sales.invoices.print', 'sales.quotations.*', 'sales.clients.view', 'sales.clients.create', 'sales.clients.edit', 'sales.returns.view', 'sales.returns.create', 'sales.payments.view', 'sales.payments.create', 'inventory.products.view', 'reports.sales.view'],
        ],
        'cashier' => [
            'ar' => 'كاشير', 'en' => 'Cashier',
            'permissions' => ['pos.terminal.use', 'pos.sessions.create', 'pos.sessions.edit', 'pos.history.view', 'sales.clients.view', 'sales.clients.create', 'inventory.products.view'],
        ],
        'warehouse_keeper' => [
            'ar' => 'أمين مستودع', 'en' => 'Warehouse Keeper',
            'permissions' => ['dashboard.*', 'inventory.products.view', 'inventory.products.create', 'inventory.products.edit', 'inventory.categories.*', 'inventory.warehouses.view', 'inventory.operations.view', 'inventory.operations.create', 'inventory.counts.view', 'inventory.counts.create', 'reports.inventory.view'],
        ],
        'purchasing' => [
            'ar' => 'مسؤول مشتريات', 'en' => 'Purchasing',
            'permissions' => ['dashboard.*', 'purchases.suppliers.*', 'purchases.orders.*', 'purchases.invoices.view', 'purchases.invoices.create', 'purchases.invoices.print', 'purchases.returns.*', 'inventory.products.view', 'inventory.costs.view'],
        ],
        'hr_manager' => [
            'ar' => 'مدير الموارد البشرية', 'en' => 'HR Manager',
            'permissions' => ['dashboard.*', 'hr.*'],
        ],
        'employee' => [
            'ar' => 'موظف', 'en' => 'Employee',
            'permissions' => ['dashboard.main.view'],
        ],
    ],

    /*
    | Route name => permission (string = required, array = any of). Every route
    | in the tenant group MUST be listed: unlisted routes are denied (fail closed).
    | '@authenticated' means any signed-in user of the company.
    */
    'routes' => [
        'dashboard' => 'dashboard.main.view',
        'files.show' => '@authenticated',
        'accounting.accounts.index' => 'accounting.accounts.view',
        'accounting.accounts.store' => 'accounting.accounts.create',
        'accounting.accounts.update' => 'accounting.accounts.edit',
        'accounting.accounts.destroy' => 'accounting.accounts.delete',
        'accounting.journals.index' => 'accounting.journals.view',
        'accounting.journals.store' => 'accounting.journals.create',
        'accounting.journals.show' => 'accounting.journals.view',
        'accounting.ledger.index' => 'accounting.ledger.view',
        'accounting.trial-balance.index' => 'accounting.trial_balance.view',
        'branches.index' => 'admin.branches.view',
        'branches.store' => 'admin.branches.create',
        'branches.destroy' => 'admin.branches.delete',
        'finance.banks.index' => 'finance.banks.view',
        'finance.banks.store' => 'finance.banks.create',
        'finance.banks.destroy' => 'finance.banks.delete',
        'finance.expenses.index' => 'finance.expenses.view',
        'finance.expenses.store' => 'finance.expenses.create',
        'finance.expenses.destroy' => 'finance.expenses.delete',
        'finance.revenues.index' => 'finance.revenues.view',
        'finance.revenues.store' => 'finance.revenues.create',
        'finance.revenues.destroy' => 'finance.revenues.delete',
        'finance.transfers.index' => 'finance.transfers.view',
        'finance.transfers.store' => 'finance.transfers.create',
        'hr.employees.index' => 'hr.employees.view',
        'hr.employees.store' => 'hr.employees.create',
        'hr.employees.destroy' => 'hr.employees.delete',
        'hr.payroll.index' => 'hr.payroll.view',
        'hr.payroll.store' => 'hr.payroll.create',
        'inventory.categories.index' => 'inventory.categories.view',
        'inventory.categories.store' => 'inventory.categories.create',
        'inventory.categories.destroy' => 'inventory.categories.delete',
        'inventory.counts.index' => 'inventory.counts.view',
        'inventory.counts.reconcile' => 'inventory.counts.create',
        'inventory.operations.index' => 'inventory.operations.view',
        'inventory.operations.adjust' => 'inventory.operations.create',
        'inventory.operations.transfer' => 'inventory.operations.create',
        'inventory.products.index' => 'inventory.products.view',
        'inventory.products.store' => 'inventory.products.create',
        'inventory.products.create' => 'inventory.products.create',
        'inventory.products.update' => 'inventory.products.edit',
        'inventory.products.destroy' => 'inventory.products.delete',
        'inventory.products.edit' => 'inventory.products.edit',
        'inventory.warehouses.index' => 'inventory.warehouses.view',
        'inventory.warehouses.store' => 'inventory.warehouses.create',
        'inventory.warehouses.update' => 'inventory.warehouses.edit',
        'inventory.warehouses.destroy' => 'inventory.warehouses.delete',
        'pos.index' => 'pos.terminal.use',
        'pos.history' => 'pos.history.view',
        'pos.order.process' => 'pos.terminal.use',
        'pos.session.open' => 'pos.sessions.create',
        'pos.session.close' => 'pos.sessions.edit',
        'purchases.invoices.index' => 'purchases.invoices.view',
        'purchases.invoices.store' => 'purchases.invoices.create',
        'purchases.invoices.create' => 'purchases.invoices.create',
        'purchases.invoices.show' => 'purchases.invoices.view',
        'purchases.invoices.destroy' => 'purchases.invoices.delete',
        'purchases.orders.index' => 'purchases.orders.view',
        'purchases.orders.convert' => 'purchases.orders.convert',
        'purchases.payments.index' => 'purchases.payments.view',
        'purchases.payments.store' => 'purchases.payments.create',
        'purchases.suppliers.index' => 'purchases.suppliers.view',
        'purchases.suppliers.store' => 'purchases.suppliers.create',
        'purchases.suppliers.update' => 'purchases.suppliers.edit',
        'purchases.suppliers.destroy' => 'purchases.suppliers.delete',
        'reports.financial' => 'reports.financial.view',
        'reports.inventory' => 'reports.inventory.view',
        'reports.sales' => 'reports.sales.view',
        'reports.vat' => 'reports.vat.view',
        'roles.index' => 'admin.roles.view',
        'roles.store' => 'admin.roles.create',
        'roles.update' => 'admin.roles.edit',
        'roles.permissions' => 'admin.roles.edit',
        'roles.duplicate' => 'admin.roles.create',
        'roles.destroy' => 'admin.roles.delete',
        'sales.clients.index' => 'sales.clients.view',
        'sales.clients.store' => 'sales.clients.create',
        'sales.clients.create' => 'sales.clients.create',
        'sales.clients.upload-attachment' => ['sales.clients.create', 'sales.clients.edit'],
        'sales.clients.show' => 'sales.clients.view',
        'sales.clients.update' => 'sales.clients.edit',
        'sales.clients.destroy' => 'sales.clients.delete',
        'sales.clients.edit' => 'sales.clients.edit',
        'sales.clients.statement' => 'sales.clients.view',
        'sales.invoices.index' => 'sales.invoices.view',
        'sales.invoices.store' => 'sales.invoices.create',
        'sales.invoices.create' => 'sales.invoices.create',
        'sales.invoices.show' => 'sales.invoices.view',
        'sales.invoices.destroy' => 'sales.invoices.delete',
        'sales.invoices.print' => 'sales.invoices.print',
        'sales.payments.index' => 'sales.payments.view',
        'sales.payments.store' => 'sales.payments.create',
        'sales.quotations.index' => 'sales.quotations.view',
        'sales.quotations.store' => 'sales.quotations.create',
        'sales.quotations.create' => 'sales.quotations.create',
        'sales.quotations.show' => 'sales.quotations.view',
        'sales.quotations.convert' => 'sales.quotations.convert',
        'sales.returns.index' => 'sales.returns.view',
        'sales.returns.store' => 'sales.returns.create',
        'sales.returns.create' => 'sales.returns.create',
        'sales.returns.show' => 'sales.returns.view',
        'settings.index' => 'admin.settings.view',
        'settings.update' => 'admin.settings.edit',
        'settings.logo.upload' => 'admin.settings.edit',
        'settings.logo.delete' => 'admin.settings.edit',
        'settings.zatca.onboard' => 'admin.zatca.manage',
        'templates.index' => 'admin.templates.view',
        'templates.store' => 'admin.templates.create',
        'templates.preview-invoice' => 'admin.templates.view',
        'templates.update' => 'admin.templates.edit',
        'templates.destroy' => 'admin.templates.delete',
        'templates.default' => 'admin.templates.edit',
        'templates.designer' => 'admin.templates.edit',
        'templates.duplicate' => 'admin.templates.create',
        'users.index' => 'admin.users.view',
        'users.store' => 'admin.users.create',
        'users.update' => 'admin.users.edit',
        'users.destroy' => 'admin.users.delete',
        'users.permissions' => 'admin.users.edit',
        'audit.index' => 'admin.audit.view',
    ],

];
