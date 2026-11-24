import { BellIcon, DocumentTextIcon, ShieldCheckIcon, UserCircleIcon } from '@heroicons/vue/24/outline';
import navigation from './navigation.js';
import { addMenu, viewMenu } from './quickMenus.js';
import { createPageSearch, normalize } from './pageSearch.js';

/**
 * The page index of the «الصفحات» search tab (GlobalSearch.vue, engine in pageSearch.js).
 *
 * The sidebar (navigation.js) is the source of the sections and their pages: a page is indexed only
 * where the sidebar would show it (canRoute: the permission the server enforces, the plan, the modules
 * switched on for the company and the active branch; and the branch's country features), and only when
 * the route exists and opens without parameters. Labels, routes and permissions are never repeated here.
 * The «إضافة» / «عرض» menus (quickMenus.js) are only read, for other names of the same pages.
 *
 * This file only adds what the sidebar cannot say:
 *  - EXTRA_PAGES: real pages outside the sidebar, under the sidebar page they belong to (`parent`).
 *    `access`: 'authenticated' (any signed-in user, route outside erp.authorize) or 'super_admin'.
 *  - PAGE_KEYWORDS / SECTION_KEYWORDS: other names people search by (Arabic and English);
 *    't:key' is an i18n key whose text in both languages is used.
 *  - PAGE_DESCRIPTIONS: the subtitle the page itself shows (its PageHeader), as an i18n key.
 *  - PAGE_WORD_FORMS: forms of one word no rule derives (broken plurals: فاتورة/فواتير).
 *  - PAGE_SYNONYMS: different words with one meaning (زبون/عميل).
 *
 * Adding a page: put it in navigation.js (shown in the sidebar too) or, outside the sidebar, in
 * EXTRA_PAGES. Its route must exist, take no required parameter and be mapped in
 * config/erp_permissions.php, or have an `access`; PageSearchIndexTest fails otherwise. Pages that need
 * a record id (show / edit) and create pages are not indexed.
 */

const KEY = 't:';

export const EXTRA_PAGES = [
    { route: 'notifications.index', label: 'x_notifications_index.notifications', icon: BellIcon, keywords: ['التنبيهات', 'alerts'] },
    {
        route: 'profile.edit', label: 'x_profile_edit.profile', icon: UserCircleIcon, access: 'authenticated',
        description: 'x_profile_edit.your_account_details_password_an',
        keywords: ['حسابي', 'كلمة المرور', 'تغيير كلمة المرور', 'التحقق الثنائي', 'المصادقة الثنائية', 'my account', 'password', 'two-factor'],
    },
    {
        route: 'settings.sector-packs', label: 'set2_settings_sectorpacks.sector_packs', parent: 'settings.index',
        description: 'set2_settings_sectorpacks.a_ready_setup_for_your_business', keywords: ['حزمة قطاع', 'إعداد جاهز للنشاط'],
    },
    {
        route: 'purchases.invoices.from-receipts', label: 'p_invoices_fromreceipts.supplier_invoice_against_receipt', parent: 'purchases.invoices.index',
        description: 'p_invoices_fromreceipts.three_way_match_purchase_order_r', keywords: ['مطابقة ثلاثية', 'فاتورة من الاستلام', 'three-way match'],
    },
    { route: 'super-admin.dashboard', label: 'nav.super_admin', icon: ShieldCheckIcon, access: 'super_admin', keywords: ['الإدارة العامة', 'إدارة المنصة', 'super admin', 'platform'] },
];

export const SECTION_KEYWORDS = {
    sales: ['البيع'],
    inventory: ['المخازن', 'المستودعات'],
    purchases: ['الشراء'],
    finance: ['الخزينة', 'النقدية', 'البنوك والصناديق'],
    accounting: ['النظام المحاسبي'],
    statements: ['التقارير المحاسبية', 'التقارير المالية', 'المحاسبة', 'financial reports'],
    reports: ['تقارير'],
    hr: ['شؤون الموظفين', 'الموظفين', 'HR'],
    settings: ['الضبط', 'التهيئة', 'preferences'],
    updates: ['الجديد', 'التحديثات'],
    pos: ['الكاشير', 'POS'],
    manufacturing: ['المصانع', 'الإنتاج'],
    projects: ['المشاريع', 'المقاولات'],
    equipment: ['المعدات', 'الصيانة'],
    real_estate: ['الأملاك', 'الإيجارات'],
    bookings: ['المواعيد'],
    support: ['الدعم الفني', 'التذاكر'],
    work_orders: ['الفنيين', 'الأعمال الميدانية'],
};

export const PAGE_KEYWORDS = {
    dashboard: ['الرئيسية', 'الصفحة الرئيسية', 'home'],
    // Sales
    'sales.invoices.index': ['فواتير المبيعات', 'فاتورة ضريبية', 'فواتير العملاء'],
    'sales.quotations.index': ['تسعيرة', 'عرض أسعار'],
    'sales.orders.index': ['طلب بيع'],
    'sales.deliveries.index': ['إذن تسليم', 'سند تسليم', 'تسليم البضاعة'],
    'sales.returns.index': ['مرتجع مبيعات', 'مرتجعات المبيعات', 'مردودات المبيعات', 'إشعار دائن', 'credit note'],
    'sales.clients.index': ['قائمة العملاء', 'حسابات العملاء', 'customers'],
    'sales.customer-groups.index': ['فئات العملاء', 'تصنيف العملاء'],
    'sales.promotions.index': ['خصومات', 'عروض ترويجية', 'كوبونات'],
    'sales.commissions.index': ['عمولة', 'عمولات المناديب'],
    'sales.commission-plans.index': ['خطة عمولة'],
    'crm.leads.index': ['العملاء المحتملين', 'فرص البيع', 'CRM'],
    'sales.payments.index': ['تحصيل', 'مقبوضات العملاء', 'دفعات العملاء'],
    'sales.debit-notes.index': ['إشعار مدين', 'debit note'],
    'sales.price-lists.index': ['لائحة الأسعار', 'أسعار خاصة'],
    'sales.recurring.index': ['فاتورة دورية', 'فواتير متكررة'],
    'sales.subscriptions.index': ['اشتراك عميل'],
    'sales.service-plans.index': ['خطة اشتراك', 'باقات الاشتراك'],
    // Point of sale
    'pos.index': ['الكاشير', 'شاشة البيع', 'نقطة البيع', 'cashier', 'POS'],
    'pos.history': ['مبيعات الكاشير', 'طلبات الكاشير'],
    'pos.open-sessions.index': ['وردية', 'الورديات'],
    'pos.registers.index': ['جهاز كاشير', 'أجهزة نقاط البيع'],
    // Inventory
    'inventory.products.index': ['الأصناف', 'منتج', 'السلع', 'items'],
    'inventory.categories.index': ['تصنيفات الأصناف', 'مجموعات الأصناف', 'فئات المنتجات'],
    'inventory.operations.index': ['t:nav.inventory_permissions', 'إذن صرف', 'إذن إضافة', 'تسوية مخزنية', 'تحويل مخزني'],
    'inventory.warehouses.index': ['مخزن', 'المخازن'],
    'inventory.stock-in.index': ['إدخال مواد', 'استلام مواد'],
    'inventory.counts.index': ['الجرد', 'جرد المخزون', 'stock count'],
    'inventory.stock-transfers.index': ['نقل مخزون', 'تحويل بين المستودعات'],
    'inventory.assembly.index': ['تجميع'],
    'inventory.reports.item-card': ['حركة الصنف', 'كرت الصنف', 'بطاقة الصنف'],
    'inventory.reports.valuation': ['قيمة المخزون', 'تكلفة المخزون'],
    'inventory.reports.reorder': ['نواقص المخزون', 'إعادة الطلب'],
    'inventory.reports.slow-moving': ['بطيئة الحركة'],
    'inventory.reports.expiry': ['انتهاء الصلاحية'],
    // Purchases
    'purchases.requests.index': ['طلب شراء'],
    'purchases.orders.index': ['أمر شراء'],
    'purchases.receipts.index': ['سند استلام', 'إذن استلام'],
    'purchases.invoices.index': ['فواتير المشتريات', 'فاتورة شراء', 'فواتير الموردين'],
    'purchases.drafts.index': ['مسودة شراء'],
    'purchases.suppliers.index': ['الموردون', 'قائمة الموردين', 'vendors'],
    'purchases.payments.index': ['دفعات الموردين', 'سداد الموردين'],
    'purchases.returns.index': ['مرتجع مشتريات', 'مردودات المشتريات'],
    'purchases.landed-costs.index': ['مصاريف الشحن', 'تكاليف الاستيراد'],
    // Finance
    'finance.payment-requests.index': ['طلب صرف', 'طلبات الدفع'],
    'finance.expenses.index': ['سند صرف', 'النفقات'],
    'finance.revenues.index': ['سند قبض'],
    'finance.banks.index': ['البنوك', 'الصناديق', 'الخزينة', 'الحسابات البنكية'],
    'finance.transfers.index': ['تحويل بين الحسابات', 'تحويل بنكي'],
    // Accounting
    'accounting.accounts.index': ['شجرة الحسابات', 'الدليل المحاسبي', 'COA'],
    'accounting.journals.index': ['قيود', 'قيد يومية', 'دفتر اليومية', 'journal'],
    'accounting.ledger.index': ['الأستاذ العام', 'دفتر الأستاذ العام', 'حركة الحساب', 'ledger'],
    'accounting.statements.consolidated': ['كشف حساب', 'كشف الحساب'],
    'accounting.trial-balance.index': ['ميزان'],
    'accounting.controls.index': ['الرقابة المحاسبية', 'جودة البيانات'],
    'accounting.recurring.index': ['قيد متكرر', 'قيود دورية'],
    'accounting.cost-centers.index': ['مركز تكلفة', 'الأبعاد التحليلية'],
    'accounting.fixed-assets.index': ['أصل ثابت', 'الإهلاك'],
    'accounting.fixed-assets.register': ['سجل الأصول'],
    'accounting.cheques.index': ['شيك'],
    'accounting.reconciliations.index': ['تسوية بنكية', 'مطابقة البنك', 'كشف البنك'],
    'accounting.budgets.index': ['الموازنة', 'الميزانية التقديرية'],
    'accounting.period-end.index': ['إقفال الفترة', 'نهاية السنة'],
    'accounting.fiscal-years.index': ['سنة مالية', 'الفترات المالية', 'إقفال السنة'],
    'accounting.mappings.index': ['توجيه الحسابات', 'الحسابات الافتراضية'],
    // Financial statements
    'accounting.reports.income-statement': ['الأرباح والخسائر', 'قائمة الأرباح والخسائر', 'profit and loss', 'P&L'],
    'accounting.reports.balance-sheet': ['الميزانية', 'الميزانية العمومية', 'قائمة المركز المالي'],
    'accounting.reports.cash-flow': ['قائمة التدفقات النقدية'],
    'accounting.reports.aging': ['أعمار الذمم', 'الذمم المدينة', 'الذمم الدائنة', 'المديونيات'],
    'accounting.reports.vat-return': ['الإقرار الضريبي', 'ضريبة القيمة المضافة', 'VAT'],
    'accounting.reports.withholding': ['الاستقطاع', 'WHT'],
    // Reports
    'reports.insights': ['التحليلات', 'مؤشرات الأداء', 'KPI'],
    'reports.sales': ['تحليل المبيعات'],
    'reports.purchases': ['تحليل المشتريات'],
    'reports.inventory': ['تحليل المخزون'],
    'reports.vat': ['تقرير الضريبة', 'الإقرار الضريبي'],
    // HR
    'hr.employees.index': ['الموظفون', 'شؤون الموظفين', 'staff'],
    'hr.payroll.index': ['مسير الرواتب', 'كشف الرواتب'],
    'hr.attendance.index': ['الدوام', 'البصمة'],
    'hr.leaves.index': ['إجازة'],
    'hr.loans.index': ['سلفة', 'قروض الموظفين'],
    'hr.admin-requests.index': ['طلب إداري'],
    'hr.settlements.index': ['مكافأة نهاية الخدمة'],
    'hr.reports.gosi': ['التأمينات الاجتماعية', 'GOSI'],
    'hr.reports.saudization': ['نطاقات', 'السعودة'],
    'hr.organization': ['t:nav.departments', 'الإدارات'],
    'hr.nationalities.index': ['جنسية'],
    'hr.settings': ['إعدادات الموارد البشرية', 'نظام العمل'],
    'hr.me': ['الخدمة الذاتية', 'طلباتي'],
    // Settings
    'settings.index': [
        't:setidx.company_details', 't:setidx.invoicing_and_tax', 't:setidx.invoicing_and_zatca', 't:setidx.accounting_mapping',
        't:setidx.currency_and_language', 't:setidx.point_of_sale_pos', 't:nav.company_profile', 't:nav.tax_settings',
        't:nav.invoice_settings', 't:nav.pos_settings', 'بيانات الشركة', 'الشعار',
    ],
    'settings.currencies': ['عملة', 'سعر الصرف'],
    'settings.modules': ['تفعيل الوحدات', 'تعطيل الوحدات'],
    'settings.messaging': ['البريد', 'الرسائل النصية', 'واتساب', 'SMS', 'email'],
    'settings.numbering': ['الترقيم', 'البادئات'],
    'users.index': ['t:nav.users_permissions', 'المستخدمون', 'حسابات المستخدمين'],
    'roles.index': ['الصلاحيات', 'الأدوار', 'permissions'],
    'audit.index': ['سجل العمليات', 'سجل النشاط'],
    'recycle-bin.index': ['المحذوفات', 'استعادة المحذوف'],
    'approval-rules.index': ['الاعتمادات', 'الموافقات', 'approvals'],
    'zatca.index': ['هيئة الزكاة', 'زاتكا', 'منصة فاتورة', 'ZATCA'],
    'billing.index': ['t:nav.subscription', 'الباقة', 'تجديد الاشتراك'],
    'integrations.index': ['API', 'Webhooks', 'التكامل'],
    'data-transfer.index': ['استيراد', 'تصدير', 'Excel', 'import', 'export'],
    // Other sections
    'branches.index': ['فرع'],
    'templates.index': ['الطباعة', 'تصميم الفاتورة', 'templates'],
    'portal.users.index': ['مستخدمو البوابة'],
    'workshop.vehicles.index': ['الورشة', 'السيارات'],
    'whats-new.index': ['التحديثات', 'سجل التغييرات', 'changelog'],
    'feature-requests.index': ['اقتراح', 'طلب ميزة'],
    'projects.index': ['مشروع', 'المستخلصات'],
    'projects.guarantees.index': ['خطاب ضمان'],
    'contracts.index': ['عقد'],
    'contracts.templates.index': ['نموذج عقد'],
    'clinic.patients.index': ['مريض', 'العيادة'],
    'real-estate.leases.index': ['إيجار', 'عقد إيجار'],
    'support.tickets.index': ['تذكرة', 'الشكاوى'],
    'support.settings.index': ['SLA', 'اتفاقيات مستوى الخدمة'],
    'bookings.index': ['حجز', 'موعد'],
    'work-orders.index': ['أمر عمل'],
    'equipment.index': ['معدة', 'التأجير'],
    'equipment.maintenance.index': ['أمر صيانة'],
    'manufacturing.orders.index': ['أمر إنتاج'],
    'manufacturing.boms.index': ['BOM'],
    'manufacturing.mrp.index': ['MRP'],
};

/** The subtitle each page shows under its title (its PageHeader): the page described in its own words. */
export const PAGE_DESCRIPTIONS = {
    'settings.index': ['setidx.company_details', 'setidx.invoicing_and_tax', 'setidx.accounting_mapping', 'setidx.currency_and_language', 'setidx.point_of_sale_pos'],
    'sales.orders.index': 's_orders.sales_order_delivery_note_invoic',
    'sales.deliveries.index': 's_deliveries.delivered_goods_are_issued_from',
    'sales.promotions.index': 's_promos.applied_automatically_to_invoice',
    'sales.commissions.index': 's_comm.each_representative_s_net_sales',
    'sales.debit-notes.index': 's_debit.additional_amounts_charged_to_th',
    'sales.price-lists.index': 's_pricelists.special_prices_per_customer_clas',
    'sales.recurring.index': 's_recurring.service_contracts_invoiced_autom',
    'sales.subscriptions.index': 's_sub_list.recurring_services_invoiced_auto',
    'sales.service-plans.index': 's_plans.prices_of_the_recurring_services',
    'pos.open-sessions.index': 'pos_open.subtitle',
    'pos.registers.index': 'pos_registers_index.the_devices_on_which_pos_session',
    'inventory.stock-in.index': 'stockin.list_subtitle',
    'inventory.counts.index': 'i_counts_index.a_full_count_or_a_spot_count_of',
    'inventory.stock-transfers.index': 'i_transfers_index.a_two_step_transfer_send_from_th',
    'inventory.assembly.index': 'i_assembly_index.issue_the_components_and_produce',
    'inventory.reports.reorder': 'i_reports_reorder.with_the_suggested_purchase_quan',
    'projects.index': 'prj_projects_index.bills_of_quantities_certificates',
    'projects.guarantees.index': 'prj_projects_guarantees.bid_performance_advance_payment',
    'projects.allocations.index': 'alloc.subtitle',
    'contracts.index': 'con2_contracts_index.customer_and_supplier_contracts',
    'contracts.templates.index': 'con2_contracts_templates.ready_texts_used_when_creating_a',
    'workshop.vehicles.index': 'x_workshop_vehicles_index.each_car_s_card_and_its_next_ser',
    'work-orders.index': 'wo2_workorders_index.field_technicians_tasks',
    'work-orders.types.index': 'wo2_workorders_types_index.the_billed_hourly_rate_for_each',
    'work-orders.timesheet': 'wo2_workorders_timesheet.technicians_hours_on_work_orders',
    'bookings.index': 'bk2_bookings_index.all_booked_appointments',
    'bookings.resources.index': 'bk2_bookings_resources_index.employees_rooms_equipment_and_co',
    'bookings.services.index': 'bk2_bookings_services_index.the_services_customers_book_with',
    'bookings.packages.index': 'bk2_bookings_packages_index.prepaid_visits_that_bookings_are',
    'support.tickets.index': 'x_support_tickets_index.customer_requests_complaints_and',
    'support.settings.index': 'x_support_settings.service_deadlines_sla_and_ticket',
    'equipment.index': 'eq2_equipment_index.hourly_daily_and_monthly_rental',
    'equipment.readings.index': 'eq2_equipment_readings.the_meter_reading_updates_mainte',
    'equipment.preparations.index': 'eq_prep.subtitle',
    'equipment.rentals.index': 'eq2_equipment_rentals_index.rental_to_customers_with_a_tax_i',
    'equipment.maintenance.dashboard': 'eq2_equipment_maintenance_dashboard.what_needs_action_now_overdue_up',
    'equipment.maintenance.index': 'eq2_equipment_maintenance_index.open_the_order_issue_spare_parts',
    'manufacturing.dashboard': 'mfg_manufacturing_dashboard.production_orders_capacity_and_q',
    'manufacturing.orders.index': 'mfg_manufacturing_orders_index.make_to_order_manufacturing_at_a',
    'manufacturing.shop-floor.index': 'mfg_manufacturing_shopfloor_index.open_operations_record_time_and',
    'manufacturing.boms.index': 'mfg_manufacturing_boms_index.each_product_s_components_and_ve',
    'manufacturing.routings.index': 'mfg_manufacturing_routings_index.operation_sequence_work_centers',
    'manufacturing.work-centers.index': 'mfg_manufacturing_workcenters_index.machines_production_lines_and_wo',
    'manufacturing.mrp.index': 'mfg_manufacturing_mrp_index.compares_demand_with_stock_purch',
    'manufacturing.capacity.index': 'mfg_manufacturing_capacity_index.each_work_center_s_load_against',
    'manufacturing.periods.index': 'mfg_manufacturing_process_periods_index.stage_cost_per_period_using_the',
    'manufacturing.stages.index': 'mfg_manufacturing_process_stages_index.a_continuous_production_line_eac',
    'manufacturing.inspections.index': 'mfg_manufacturing_quality_inspections_index.receipt_inspection_results_accep',
    'manufacturing.plans.index': 'mfg_manufacturing_quality_plans_index.the_inspection_items_a_product_i',
    'manufacturing.subcontracts.index': 'mfg_manufacturing_subcontracts_index.we_send_raw_materials_to_an_exte',
    'purchases.requests.index': 'p_requests_index.internal_request_approval_purcha',
    'purchases.receipts.index': 'p_receipts_index.received_uninvoiced_goods_appear',
    'purchases.drafts.index': 'pdraft.subtitle',
    'purchases.landed-costs.index': 'p_landedcosts_index.freight_customs_and_clearance_ar',
    'finance.payment-requests.index': 'prq.subtitle',
    'accounting.statements.consolidated': 'a_consol.subtitle',
    'accounting.recurring.index': 'a_recurring.entries_posted_automatically_on',
    'accounting.cost-centers.index': 'a_cc.analytical_dimensions_to_distrib',
    'accounting.cheques.index': 'a_cheques.cheques_received_from_customers',
    'accounting.reconciliations.index': 'a_recs.match_the_bank_movements_in_the',
    'accounting.budgets.index': 'a_budgets.plan_monthly_revenue_and_expense',
    'accounting.period-end.index': 'a_period_end.foreign_currency_revaluation_mon',
    'accounting.fiscal-years.index': 'a_years.closing_periods_blocks_posting_o',
    'accounting.mappings.index': 'a_mappings.the_accounts_the_system_posts_in',
    'reports.financial': 'rep2_reports_financial.a_comprehensive_financial_perfor',
    'hr.employees.index': 'hr_employees_index.employee_files_documents_salarie',
    'hr.payroll.index': 'hr_payroll_index.payroll_with_overtime_absence_le',
    'hr.attendance.index': 'hr_attendance.lateness_and_overtime_follow_the',
    'hr.leaves.index': 'hr_leaves.per_the_labor_law_annual_21_30_d',
    'hr.loans.index': 'hr_loans.paid_on_approval_installments_ar',
    'hr.admin-requests.index': 'hr_adminrequests_index.summon_employees_to_attend_and_f',
    'hr.settlements.index': 'hr_settlements.article_84_half_a_month_per_year',
    'hr.reports.saudization': 'hr_reports_saudization.an_estimate_from_employee_data_t',
    'hr.nationalities.index': 'hr_nationalities_index.the_list_of_nationalities_availa',
    'branches.index': 'x_branches_index.each_branch_is_in_a_country_its',
    'settings.modules': 'set2_settings_modules.a_disabled_module_disappears_fro',
    'settings.messaging': 'set2_settings_messaging.the_company_s_own_mail_smtp_and',
    'settings.numbering': 'set2_settings_numbering.a_prefix_digit_count_and_numberi',
    'approval-rules.index': 'set2_settings_approvalrules.documents_reaching_the_limit_wai',
    'zatca.index': 'zatca.integration_with_zatca_xml_signi',
    'integrations.index': 'set2_settings_integrations.api_tokens_for_your_other_system',
    'data-transfer.index': 'set2_settings_datatransfer.csv_or_excel_all_rows_are_valida',
    'feature-requests.index': 'x_featurerequests_index.your_request_reaches_the_system',
};

/**
 * Forms of one word that the engine's rules (article, ات/ون/ين/ة endings) do not derive, mostly broken
 * plurals: a match through them counts as the same word (title tiers), «فاتورة» finds «الفواتير».
 */
export const PAGE_WORD_FORMS = [
    ['فاتورة', 'فواتير'], ['قيد', 'قيود'], ['تقرير', 'تقارير'], ['عميل', 'عملاء'], ['زبون', 'زبائن'],
    ['صنف', 'أصناف'], ['أمر', 'أوامر'], ['عقد', 'عقود'], ['مشروع', 'مشاريع'], ['قائمة', 'قوائم'],
    ['خزينة', 'خزائن'], ['صندوق', 'صناديق'], ['بنك', 'بنوك'], ['مصرف', 'مصارف'], ['راتب', 'رواتب'],
    ['مخزن', 'مخازن'], ['ضريبة', 'ضرائب'], ['دور', 'أدوار'], ['فرع', 'فروع'], ['حجز', 'حجوزات'],
    ['موعد', 'مواعيد'], ['مريض', 'مرضى'], ['أصل', 'أصول'], ['تذكرة', 'تذاكر'], ['خطة', 'خطط'],
    ['نقطة', 'نقاط'], ['سلفة', 'سلف'], ['ورشة', 'ورش'], ['مادة', 'مواد'], ['قسم', 'أقسام'],
    ['مهمة', 'مهام'], ['رسالة', 'رسائل'], ['قالب', 'قوالب'], ['نموذج', 'نماذج'], ['عرض', 'عروض'],
    ['سعر', 'أسعار'], ['تكلفة', 'تكاليف'], ['مصنع', 'مصانع'], ['جهاز', 'أجهزة'], ['سنة', 'سنوات'],
    ['وثيقة', 'وثائق'], ['خدمة', 'خدمات'], ['شيك', 'شيكات'], ['مسير', 'مسيرات'], ['ضمان', 'ضمانات'],
];

/** Different words with one meaning (a match through them ranks with the keywords). */
export const PAGE_SYNONYMS = [
    ['عميل', 'زبون'],
    ['مورد', 'مزود'],
    ['صنف', 'منتج', 'سلعة'],
    ['خزينة', 'صندوق'],
    ['بنك', 'مصرف'],
    ['مستودع', 'مخزن'],
    ['مصروف', 'نفقة'],
    ['حجز', 'موعد'],
    ['مركبة', 'سيارة'],
    ['إشعار', 'تنبيه'],
    ['مرتجع', 'مردود'],
    ['كاشير', 'صراف'],
    ['client', 'customer'],
    ['supplier', 'vendor'],
    ['product', 'item'],
    ['setting', 'preference'],
];

/**
 * Can the user open this page here? The route exists (Ziggy), the branch's country has its feature, and
 * the server would let the user in: canRoute (permission, plan, company and branch modules), or the
 * page's `access`. Its URL needs no parameter. Returns the URL, or null.
 */
function openUrl(env, route, { access, feature } = {}) {
    if (!route || !env.routeExists(route)) return null;
    if (feature && !env.hasFeature(feature)) return null;
    const allowed = access === 'super_admin' ? env.superAdmin : access === 'authenticated' ? env.signedIn : env.canRoute(route);
    if (!allowed) return null;
    try {
        return env.urlFor(route) || null;
    } catch {
        return null;
    }
}

/** A page registered under a sidebar page takes that page's country feature, unless it names its own. */
const under = (extra, parent) => ({ ...extra, feature: extra.feature ?? parent.feature });

/** Every page the index may hold, in sidebar order, with what decides whether the user sees it. */
export function candidatePages() {
    const out = [];
    for (const item of navigation) {
        if (!item.children) out.push({ route: item.route });
        for (const child of item.children || []) {
            out.push({ route: child.route, feature: child.feature });
            for (const x of EXTRA_PAGES.filter((p) => p.parent === child.route)) out.push(under(x, child));
        }
    }
    return [...out, ...EXTRA_PAGES.filter((p) => !p.parent)];
}

/** What the index depends on, as a string: rebuilt only when it changes (cheap, no translation). */
export function visibilityKey(env) {
    return candidatePages().map((p) => (openUrl(env, p.route, p) ? '1' : '0')).join('');
}

// One index per browser tab: built for a scope (language, company, user) and the pages visible there.
let cache = { key: null, search: null };

/**
 * The page search for `env`, reused while nothing it depends on changed: the scope (language, company,
 * user) and which pages are visible (permissions, plan, company and branch modules, country features).
 * Anything else rebuilds it, so one company's or user's index never answers for another.
 */
export function pageSearchFor(env, scope) {
    const key = `${scope}|${visibilityKey(env)}`;
    if (cache.key !== key) {
        cache = { key, search: createPageSearch(buildPageEntries(env), { forms: PAGE_WORD_FORMS, synonyms: PAGE_SYNONYMS }) };
    }
    return cache.search;
}

/** The module of a permission: its first segment («work_orders.orders.view» → work_orders). */
export const moduleOf = (permission) => {
    const first = Array.isArray(permission) ? permission[0] : permission;
    return typeof first === 'string' && !first.startsWith('@') ? first.split('.')[0] : null;
};

/**
 * The entries of the index for the user here: sections (with the ids of their visible pages, a page
 * outside the sidebar right after its parent) and pages. A section with no page the user may open is
 * left out, and a page appears once even when several sources name it.
 *
 * env: t(key) / tOther(key) → text in the interface / other language ('' when missing); plain(text) /
 * plainOther(text) → a fixed Arabic menu label (quickMenus.js) in either language; canRoute(name);
 * hasFeature(key); routeExists(name); urlFor(name) (throws when parameters are needed);
 * permissionOf(name) → the permission the server maps the route to; signedIn; superAdmin; separator.
 */
export function buildPageEntries(env) {
    const words = (list) => (list || []).flatMap((w) => (w.startsWith(KEY) ? [env.t(w.slice(KEY.length)), env.tOther(w.slice(KEY.length))] : [w]));
    const describe = (spec) => (Array.isArray(spec) ? spec : spec ? [spec] : []).map((k) => env.t(k)).filter(Boolean).join(env.separator || '، ');

    // Other names from the top-bar menus: the «عرض» label of the same route, the «إضافة» label of its create page.
    const menuNames = new Map();
    const addName = (route, label) => menuNames.set(route, [...(menuNames.get(route) || []), env.plain(label), env.plainOther(label)]);
    viewMenu.forEach((item) => addName(item.route, item.label));
    addMenu.forEach((group) => group.items.forEach((item) => {
        if (item.route.endsWith('.create')) addName(`${item.route.slice(0, -'.create'.length)}.index`, item.label);
    }));

    const uniqueWords = (title, list) => {
        const seen = new Set([normalize(title)]);
        return list.filter((w) => {
            const n = normalize(w);
            if (!n || seen.has(n)) return false;
            seen.add(n);
            return true;
        });
    };

    const placed = new Set();
    let order = 0;
    const makePage = (spec, { section = null, parent = null, level }) => {
        if (placed.has(spec.route)) return null;
        const url = openUrl(env, spec.route, spec);
        const title = url ? env.t(spec.label) : '';
        if (!title) return null;
        placed.add(spec.route);
        const permission = spec.access ? `@${spec.access}` : (env.permissionOf(spec.route) ?? null);
        return {
            id: `page:${spec.route}`,
            type: 'page',
            routeName: spec.route,
            url,
            title,
            category: section?.title || '',
            sectionId: section?.id || null,
            parent: parent?.id || section?.id || null,
            parentTitle: parent?.title || '',
            path: [section?.title, parent?.title, title].filter(Boolean),
            keywords: uniqueWords(title, [
                env.tOther(spec.label),
                ...words(PAGE_KEYWORDS[spec.route]),
                ...words(spec.keywords),
                ...(menuNames.get(spec.route) || []),
            ]),
            description: describe(spec.description ?? PAGE_DESCRIPTIONS[spec.route]),
            permission,
            module: moduleOf(permission),
            icon: spec.icon || section?.icon || DocumentTextIcon,
            level,
            order: order++,
        };
    };

    const entries = [];
    for (const item of navigation) {
        if (!item.children) {
            const page = makePage(item, { level: 1 });
            if (page) entries.push(page);
            continue;
        }
        const title = env.t(item.label);
        const section = {
            id: `section:${item.key}`,
            type: 'section',
            key: item.key,
            title,
            category: '',
            parent: null,
            parentTitle: '',
            path: [title],
            routeName: null,
            url: null,
            keywords: uniqueWords(title, [env.tOther(item.label), ...words(SECTION_KEYWORDS[item.key])]),
            description: '',
            permission: null,
            module: null,
            icon: item.icon,
            level: 1,
            order: order++,
            children: [],
        };
        const pages = [];
        for (const child of item.children) {
            const page = makePage(child, { section, level: 2 });
            if (page) pages.push(page);
            for (const extra of EXTRA_PAGES.filter((p) => p.parent === child.route)) {
                // Under its parent when the user may open the parent; else straight under the section.
                const sub = makePage(under(extra, child), { section, parent: page, level: page ? 3 : 2 });
                if (sub) pages.push(sub);
            }
        }
        if (pages.length) {
            section.children = pages.map((p) => p.id);
            entries.push(section, ...pages);
        }
    }
    for (const extra of EXTRA_PAGES.filter((p) => !p.parent)) {
        const page = makePage(extra, { level: 1 });
        if (page) entries.push(page);
    }
    return entries;
}
