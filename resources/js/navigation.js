import {
    HomeIcon,
    ShoppingCartIcon,
    DeviceTabletIcon,
    ArchiveBoxIcon,
    TruckIcon,
    BanknotesIcon,
    BookOpenIcon,
    ChartBarIcon,
    IdentificationIcon,
    BuildingStorefrontIcon,
    PrinterIcon,
    Cog6ToothIcon,
    UsersIcon,
    DocumentChartBarIcon,
} from '@heroicons/vue/24/outline';

/**
 * Sidebar definition. Items are shown only when the user may open their route
 * (see canRoute in permissions.js); groups with no visible item are hidden.
 */
export default [
    { key: 'dashboard', label: 'nav.dashboard', icon: HomeIcon, route: 'dashboard', path: '/dashboard' },
    {
        key: 'sales', label: 'nav.sales', icon: ShoppingCartIcon, color: 'indigo',
        children: [
            { label: 'nav.invoices', route: 'sales.invoices.index', path: '/sales/invoices' },
            { label: 'nav.quotations', route: 'sales.quotations.index', path: '/sales/quotations' },
            { label: 'nav.credit_notes', route: 'sales.returns.index', path: '/sales/returns' },
            { label: 'nav.clients', route: 'sales.clients.index', path: '/sales/clients' },
            { label: 'nav.payments_received', route: 'sales.payments.index', path: '/sales/payments' },
        ],
    },
    {
        key: 'pos', label: 'nav.pos', icon: DeviceTabletIcon, color: 'emerald',
        children: [
            { label: 'nav.pos_session', route: 'pos.index', path: '/pos', exact: true },
            { label: 'nav.pos_history', route: 'pos.history', path: '/pos/history' },
        ],
    },
    {
        key: 'inventory', label: 'nav.inventory', icon: ArchiveBoxIcon, color: 'indigo',
        children: [
            { label: 'nav.products', route: 'inventory.products.index', path: '/inventory/products' },
            { label: 'nav.categories', route: 'inventory.categories.index', path: '/inventory/categories' },
            { label: 'nav.inventory_operations', route: 'inventory.operations.index', path: '/inventory/operations' },
            { label: 'nav.warehouses', route: 'inventory.warehouses.index', path: '/inventory/warehouses' },
            { label: 'nav.inventory_count', route: 'inventory.counts.index', path: '/inventory/counts' },
        ],
    },
    {
        key: 'purchases', label: 'nav.purchases', icon: TruckIcon, color: 'indigo',
        children: [
            { label: 'nav.purchase_orders', route: 'purchases.orders.index', path: '/purchases/orders' },
            { label: 'nav.purchase_invoices', route: 'purchases.invoices.index', path: '/purchases/invoices' },
            { label: 'nav.suppliers', route: 'purchases.suppliers.index', path: '/purchases/suppliers' },
            { label: 'nav.supplier_payments', route: 'purchases.payments.index', path: '/purchases/payments' },
        ],
    },
    {
        key: 'finance', label: 'nav.finance', icon: BanknotesIcon, color: 'indigo',
        children: [
            { label: 'nav.expenses', route: 'finance.expenses.index', path: '/finance/expenses' },
            { label: 'nav.revenues', route: 'finance.revenues.index', path: '/finance/revenues' },
            { label: 'nav.treasury_banks', route: 'finance.banks.index', path: '/finance/banks' },
            { label: 'nav.transfers', route: 'finance.transfers.index', path: '/finance/transfers' },
        ],
    },
    {
        key: 'accounting', label: 'nav.accounting', icon: BookOpenIcon, color: 'indigo',
        children: [
            { label: 'nav.chart_of_accounts', route: 'accounting.accounts.index', path: '/accounting/accounts' },
            { label: 'nav.journal_entries', route: 'accounting.journals.index', path: '/accounting/journals' },
            { label: 'nav.general_ledger', route: 'accounting.ledger.index', path: '/accounting/ledger' },
            { label: 'nav.trial_balance', route: 'accounting.trial-balance.index', path: '/accounting/trial-balance' },
            { label: 'nav.recurring_journals', route: 'accounting.recurring.index', path: '/accounting/recurring' },
            { label: 'nav.cost_centers', route: 'accounting.cost-centers.index', path: '/accounting/cost-centers' },
            { label: 'nav.fixed_assets', route: 'accounting.fixed-assets.index', path: '/accounting/fixed-assets' },
            { label: 'nav.cheques', route: 'accounting.cheques.index', path: '/accounting/cheques' },
            { label: 'nav.reconciliations', route: 'accounting.reconciliations.index', path: '/accounting/reconciliations' },
            { label: 'nav.budgets', route: 'accounting.budgets.index', path: '/accounting/budgets' },
            { label: 'nav.fiscal_years', route: 'accounting.fiscal-years.index', path: '/accounting/fiscal-years' },
            { label: 'nav.account_mappings', route: 'accounting.mappings.index', path: '/accounting/mappings' },
        ],
    },
    {
        key: 'statements', label: 'nav.financial_statements', icon: DocumentChartBarIcon, color: 'indigo',
        children: [
            { label: 'nav.income_statement', route: 'accounting.reports.income-statement', path: '/accounting/reports/income-statement' },
            { label: 'nav.balance_sheet', route: 'accounting.reports.balance-sheet', path: '/accounting/reports/balance-sheet' },
            { label: 'nav.cash_flow', route: 'accounting.reports.cash-flow', path: '/accounting/reports/cash-flow' },
            { label: 'nav.aging', route: 'accounting.reports.aging', path: '/accounting/reports/aging' },
            { label: 'nav.vat_return', route: 'accounting.reports.vat-return', path: '/accounting/reports/vat-return' },
        ],
    },
    {
        key: 'reports', label: 'nav.reports', icon: ChartBarIcon, color: 'indigo',
        children: [
            { label: 'nav.sales_reports', route: 'reports.sales', path: '/reports/sales' },
            { label: 'nav.inventory_reports', route: 'reports.inventory', path: '/reports/inventory' },
            { label: 'nav.financial_reports', route: 'reports.financial', path: '/reports/financial' },
            { label: 'nav.vat_reports', route: 'reports.vat', path: '/reports/vat' },
        ],
    },
    {
        key: 'hr', label: 'nav.hr', icon: IdentificationIcon, color: 'indigo',
        children: [
            { label: 'nav.employees', route: 'hr.employees.index', path: '/hr/employees' },
            { label: 'nav.payroll', route: 'hr.payroll.index', path: '/hr/payroll' },
        ],
    },
    { key: 'branches', label: 'nav.branches', icon: BuildingStorefrontIcon, route: 'branches.index', path: '/branches' },
    { key: 'templates', label: 'nav.templates', icon: PrinterIcon, route: 'templates.index', path: '/templates' },
    {
        key: 'settings', label: 'nav.settings', icon: Cog6ToothIcon, color: 'indigo',
        children: [
            { label: 'nav.general_settings', route: 'settings.index', path: '/settings' },
            { label: 'nav.users', route: 'users.index', path: '/users' },
            { label: 'nav.roles', route: 'roles.index', path: '/roles' },
            { label: 'nav.audit_log', route: 'audit.index', path: '/audit-log' },
        ],
    },
];

export { UsersIcon };
