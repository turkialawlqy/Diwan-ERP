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
    BuildingOffice2Icon,
    WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline';

/**
 * Sidebar definition. Items are shown only when the user may open their route
 * (see canRoute in permissions.js); groups with no visible item are hidden.
 * An item with `country` shows only when the company has a branch there
 * (ZATCA, GOSI and Saudization are Saudi).
 */
export default [
    { key: 'dashboard', label: 'nav.dashboard', icon: HomeIcon, route: 'dashboard', path: '/dashboard' },
    {
        key: 'sales', label: 'nav.sales', icon: ShoppingCartIcon, color: 'indigo',
        children: [
            { label: 'nav.invoices', route: 'sales.invoices.index', path: '/sales/invoices' },
            { label: 'nav.quotations', route: 'sales.quotations.index', path: '/sales/quotations' },
            { label: 'nav.sales_orders', route: 'sales.orders.index', path: '/sales/orders' },
            { label: 'nav.delivery_notes', route: 'sales.deliveries.index', path: '/sales/deliveries' },
            { label: 'nav.credit_notes', route: 'sales.returns.index', path: '/sales/returns' },
            { label: 'nav.clients', route: 'sales.clients.index', path: '/sales/clients' },
            { label: 'nav.crm_leads', route: 'crm.leads.index', path: '/crm/leads' },
            { label: 'nav.payments_received', route: 'sales.payments.index', path: '/sales/payments' },
            { label: 'nav.debit_notes', route: 'sales.debit-notes.index', path: '/sales/debit-notes' },
            { label: 'nav.price_lists', route: 'sales.price-lists.index', path: '/sales/price-lists' },
            { label: 'nav.recurring_invoices', route: 'sales.recurring.index', path: '/sales/recurring' },
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
            { label: 'nav.stock_transfers', route: 'inventory.stock-transfers.index', path: '/inventory/stock-transfers' },
            { label: 'nav.assembly', route: 'inventory.assembly.index', path: '/inventory/assembly' },
            { label: 'nav.item_card', route: 'inventory.reports.item-card', path: '/inventory/reports/item-card' },
            { label: 'nav.stock_valuation', route: 'inventory.reports.valuation', path: '/inventory/reports/valuation' },
            { label: 'nav.reorder', route: 'inventory.reports.reorder', path: '/inventory/reports/reorder' },
            { label: 'nav.slow_moving', route: 'inventory.reports.slow-moving', path: '/inventory/reports/slow-moving' },
            { label: 'nav.expiry', route: 'inventory.reports.expiry', path: '/inventory/reports/expiry' },
        ],
    },
    {
        key: 'projects', label: 'nav.projects', icon: BuildingOffice2Icon, color: 'indigo',
        children: [
            { label: 'nav.projects_list', route: 'projects.index', path: '/projects' },
            { label: 'nav.guarantees', route: 'projects.guarantees.index', path: '/projects/guarantees' },
        ],
    },
    {
        key: 'equipment', label: 'nav.equipment', icon: WrenchScrewdriverIcon, color: 'indigo',
        children: [
            { label: 'nav.equipment_list', route: 'equipment.index', path: '/equipment', exact: true },
            { label: 'nav.equipment_readings', route: 'equipment.readings.index', path: '/equipment/readings' },
            { label: 'nav.equipment_rentals', route: 'equipment.rentals.index', path: '/equipment/rentals' },
            { label: 'nav.maintenance_dashboard', route: 'equipment.maintenance.dashboard', path: '/equipment/maintenance/dashboard' },
            { label: 'nav.maintenance_orders', route: 'equipment.maintenance.index', path: '/equipment/maintenance', exact: true },
            { label: 'nav.equipment_reports', route: 'equipment.reports.index', path: '/equipment/reports' },
        ],
    },
    {
        key: 'purchases', label: 'nav.purchases', icon: TruckIcon, color: 'indigo',
        children: [
            { label: 'nav.purchase_requests', route: 'purchases.requests.index', path: '/purchases/requests' },
            { label: 'nav.purchase_orders', route: 'purchases.orders.index', path: '/purchases/orders' },
            { label: 'nav.goods_receipts', route: 'purchases.receipts.index', path: '/purchases/receipts' },
            { label: 'nav.purchase_invoices', route: 'purchases.invoices.index', path: '/purchases/invoices' },
            { label: 'nav.suppliers', route: 'purchases.suppliers.index', path: '/purchases/suppliers' },
            { label: 'nav.supplier_payments', route: 'purchases.payments.index', path: '/purchases/payments' },
            { label: 'nav.purchase_returns', route: 'purchases.returns.index', path: '/purchases/returns' },
            { label: 'nav.landed_costs', route: 'purchases.landed-costs.index', path: '/purchases/landed-costs' },
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
            { label: 'nav.asset_register', route: 'accounting.fixed-assets.register', path: '/accounting/fixed-assets/register' },
            { label: 'nav.cheques', route: 'accounting.cheques.index', path: '/accounting/cheques' },
            { label: 'nav.reconciliations', route: 'accounting.reconciliations.index', path: '/accounting/reconciliations' },
            { label: 'nav.budgets', route: 'accounting.budgets.index', path: '/accounting/budgets' },
            { label: 'nav.period_end', route: 'accounting.period-end.index', path: '/accounting/period-end' },
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
            { label: 'nav.insights', route: 'reports.insights', path: '/reports/insights' },
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
            { label: 'nav.attendance', route: 'hr.attendance.index', path: '/hr/attendance' },
            { label: 'nav.leaves', route: 'hr.leaves.index', path: '/hr/leaves' },
            { label: 'nav.loans', route: 'hr.loans.index', path: '/hr/loans' },
            { label: 'nav.settlements', route: 'hr.settlements.index', path: '/hr/settlements' },
            { label: 'nav.gosi_report', route: 'hr.reports.gosi', path: '/hr/reports/gosi', country: 'SA' },
            { label: 'nav.saudization_report', route: 'hr.reports.saudization', path: '/hr/reports/saudization', country: 'SA' },
            { label: 'nav.organization', route: 'hr.organization', path: '/hr/organization' },
            { label: 'nav.hr_settings', route: 'hr.settings', path: '/hr/settings' },
            { label: 'nav.self_service', route: 'hr.me', path: '/hr/me' },
        ],
    },
    { key: 'branches', label: 'nav.branches', icon: BuildingStorefrontIcon, route: 'branches.index', path: '/branches' },
    { key: 'templates', label: 'nav.templates', icon: PrinterIcon, route: 'templates.index', path: '/templates' },
    {
        key: 'settings', label: 'nav.settings', icon: Cog6ToothIcon, color: 'indigo',
        children: [
            { label: 'nav.general_settings', route: 'settings.index', path: '/settings' },
            { label: 'nav.currencies', route: 'settings.currencies', path: '/settings/currencies' },
            { label: 'nav.users', route: 'users.index', path: '/users' },
            { label: 'nav.roles', route: 'roles.index', path: '/roles' },
            { label: 'nav.audit_log', route: 'audit.index', path: '/audit-log' },
            { label: 'nav.approval_rules', route: 'approval-rules.index', path: '/approval-rules' },
            { label: 'nav.e_invoicing', route: 'zatca.index', path: '/zatca', country: 'SA' },
            { label: 'nav.billing', route: 'billing.index', path: '/billing' },
            { label: 'nav.integrations', route: 'integrations.index', path: '/integrations' },
            { label: 'nav.data_transfer', route: 'data-transfer.index', path: '/data-transfer' },
        ],
    },
];

export { UsersIcon };
