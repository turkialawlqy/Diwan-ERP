import {
    BuildingOffice2Icon, TruckIcon, UserCircleIcon, BuildingLibraryIcon, DocumentTextIcon, ClipboardDocumentListIcon,
    ShoppingCartIcon, ArrowUturnLeftIcon, DocumentPlusIcon, CreditCardIcon, BookOpenIcon, ArrowsRightLeftIcon,
    CubeIcon, BuildingStorefrontIcon, ArchiveBoxIcon, ClipboardDocumentCheckIcon, Cog6ToothIcon, BriefcaseIcon,
    BanknotesIcon, ListBulletIcon, SparklesIcon, LightBulbIcon,
} from '@heroicons/vue/24/outline';

/**
 * The top-bar «إضافة» (create) and «عرض» (view) menus. An item shows only when the
 * user may open its route (canRoute); a group with no visible item is hidden.
 * Every route name must exist in config/erp_permissions.php (QuickMenusTest).
 * New create / list pages worth a shortcut are registered here.
 */
export const addMenu = [
    { label: 'الأطراف', items: [
        { label: 'عميل', route: 'sales.clients.create', icon: BuildingOffice2Icon },
        { label: 'مورد', route: 'purchases.suppliers.create', icon: TruckIcon },
        { label: 'موظف', route: 'hr.employees.create', icon: UserCircleIcon },
    ] },
    { label: 'المبيعات', items: [
        { label: 'فاتورة مبيعات', route: 'sales.invoices.create', icon: DocumentTextIcon },
        { label: 'عرض سعر', route: 'sales.quotations.create', icon: ClipboardDocumentListIcon },
        { label: 'أمر بيع', route: 'sales.orders.create', icon: ShoppingCartIcon },
        { label: 'مرتجع مبيعات', route: 'sales.returns.create', icon: ArrowUturnLeftIcon },
        { label: 'اشتراك عميل', route: 'sales.subscriptions.create', icon: CreditCardIcon },
        { label: 'تذكرة دعم', route: 'support.tickets.create', icon: ClipboardDocumentListIcon },
        { label: 'عقد', route: 'contracts.create', icon: DocumentTextIcon },
    ] },
    { label: 'المشتريات', items: [
        { label: 'فاتورة مشتريات', route: 'purchases.invoices.create', icon: DocumentPlusIcon },
        { label: 'أمر شراء', route: 'purchases.orders.create', icon: ShoppingCartIcon },
        { label: 'دفعة لمورد', route: 'purchases.payments.create', icon: CreditCardIcon },
    ] },
    { label: 'الخزينة', items: [
        { label: 'بنك أو صندوق', route: 'finance.banks.create', icon: BuildingLibraryIcon },
        { label: 'سند قبض', route: 'finance.revenues.create', icon: DocumentTextIcon },
        { label: 'سند صرف', route: 'finance.expenses.create', icon: CreditCardIcon },
        { label: 'تحويل بين حسابات', route: 'finance.transfers.create', icon: ArrowsRightLeftIcon },
    ] },
    { label: 'المحاسبة', items: [
        { label: 'قيد يومي', route: 'accounting.journals.create', icon: BookOpenIcon },
    ] },
    { label: 'المخزون', items: [
        { label: 'صنف', route: 'inventory.products.create', icon: CubeIcon },
        { label: 'مستودع', route: 'inventory.warehouses.create', icon: BuildingStorefrontIcon },
        { label: 'تحويل مخزني', route: 'inventory.operations.transfer.create', icon: ArchiveBoxIcon },
    ] },
    { label: 'التصنيع والمشاريع', items: [
        { label: 'أمر إنتاج', route: 'manufacturing.orders.create', icon: Cog6ToothIcon },
        { label: 'مشروع', route: 'projects.create', icon: BriefcaseIcon },
    ] },
];

export const viewMenu = [
    { label: 'عمولات المبيعات', route: 'sales.commissions.index', icon: BanknotesIcon },
    { label: 'العروض والخصومات', route: 'sales.promotions.index', icon: SparklesIcon },
    { label: 'أبرز التحديثات', route: 'whats-new.index', icon: SparklesIcon },
    { label: 'طلبات المميزات', route: 'feature-requests.index', icon: LightBulbIcon },
    { label: 'العملاء', route: 'sales.clients.index', icon: BuildingOffice2Icon },
    { label: 'الموردون', route: 'purchases.suppliers.index', icon: TruckIcon },
    { label: 'الموظفون', route: 'hr.employees.index', icon: UserCircleIcon },
    { label: 'المنتجات', route: 'inventory.products.index', icon: CubeIcon },
    { label: 'فواتير المبيعات', route: 'sales.invoices.index', icon: DocumentTextIcon },
    { label: 'اشتراكات العملاء', route: 'sales.subscriptions.index', icon: CreditCardIcon },
    { label: 'تذاكر الدعم', route: 'support.tickets.index', icon: ClipboardDocumentListIcon },
    { label: 'العقود', route: 'contracts.index', icon: DocumentTextIcon },
    { label: 'فواتير المشتريات', route: 'purchases.invoices.index', icon: DocumentPlusIcon },
    { label: 'سندات القبض', route: 'finance.revenues.index', icon: BanknotesIcon },
    { label: 'سندات الصرف', route: 'finance.expenses.index', icon: CreditCardIcon },
    { label: 'القيود اليومية', route: 'accounting.journals.index', icon: BookOpenIcon },
    { label: 'دليل الحسابات', route: 'accounting.accounts.index', icon: ListBulletIcon },
    { label: 'الجرد', route: 'inventory.counts.index', icon: ClipboardDocumentCheckIcon },
];
