import { taxRate, taxInfo } from '@/utils/tax';
import { today } from '@/utils/format';

/**
 * Entities that can be created on the fly from any dropdown ("إنشاء «...»").
 * The dialog shows every field of the entity's own create page and posts to the
 * same store route (which answers JSON for an AJAX call).
 *
 * To make a new master-data entity creatable from dropdowns, add it here, make
 * its store action return `$this->createdResponse(...)`, and use <SelectCreate
 * entity="..."> wherever it is picked. Field: { key, label, type, required,
 * span (2 = full row), lookup (a lookup.list name), entity (nested create),
 * options [{ value, label }], default, hint, show(form) }.
 */
const text = (key, label, extra = {}) => ({ key, label, type: 'text', ...extra });
const num = (key, label, extra = {}) => ({ key, label, type: 'number', step: '0.01', default: 0, ...extra });
const pick = (key) => (data) => data[key];

export const quickCreate = {
    warehouse: {
        title: 'مستودع جديد', route: 'inventory.warehouses.store', seed: 'name_ar',
        fields: () => [
            text('name_ar', 'اسم المستودع (بالعربي)', { required: true, span: 2 }),
            text('name_en', 'الاسم (بالإنجليزي)'),
            text('code', 'كود المستودع', { required: true }),
            { key: 'branch_id', label: 'الفرع', type: 'select', lookup: 'branches', defaultFirst: true },
            text('phone', 'الهاتف'),
            text('address', 'العنوان', { span: 2 }),
            { key: 'is_main', label: 'مستودع رئيسي', type: 'checkbox', default: false },
        ],
    },
    category: {
        title: 'تصنيف جديد', route: 'inventory.categories.store', seed: 'name_ar', pick: pick('category'), label: (r) => r.name_ar,
        fields: () => [
            text('name_ar', 'اسم التصنيف (بالعربي)', { required: true, span: 2 }),
            text('name_en', 'الاسم (بالإنجليزي)'),
            text('code', 'الرمز'),
            { key: 'color', label: 'اللون', type: 'color', default: '#6366f1' },
        ],
    },
    unit: {
        title: 'وحدة قياس جديدة', route: 'inventory.units.store', seed: 'name_ar',
        fields: () => [
            text('name_ar', 'اسم الوحدة (بالعربي)', { required: true }),
            text('name_en', 'الاسم (بالإنجليزي)'),
            text('code', 'الرمز'),
        ],
    },
    product: {
        title: 'صنف جديد', route: 'inventory.products.store', seed: 'name_ar', size: 'lg', pick: pick('product'), label: (r) => r.name_ar,
        fields: () => [
            { key: 'type', label: 'نوع البند', type: 'select', options: [{ value: 'product', label: 'منتج' }, { value: 'service', label: 'خدمة' }], default: 'product' },
            text('name_ar', 'اسم الصنف (بالعربي)', { required: true }),
            text('name_en', 'الاسم (بالإنجليزي)'),
            text('sku', 'رمز الصنف (SKU)'),
            text('barcode', 'الباركود'),
            { key: 'category_id', label: 'التصنيف', type: 'select', lookup: 'categories', entity: 'category', defaultFirst: true },
            { key: 'unit_id', label: 'الوحدة', type: 'select', lookup: 'units', entity: 'unit', defaultFirst: true },
            num('purchase_price', 'سعر الشراء'),
            num('selling_price', 'سعر البيع', { required: true }),
            num('tax_rate', 'نسبة الضريبة %', { default: taxRate(), show: () => taxInfo().has_tax !== false }),
            { key: 'vat_category', label: 'الفئة الضريبية', type: 'select', default: 'S', show: () => taxInfo().has_tax !== false && taxInfo().country === 'SA',
                options: [{ value: 'S', label: 'S — خاضع للنسبة الأساسية' }, { value: 'Z', label: 'Z — نسبة صفرية' }, { value: 'E', label: 'E — معفى' }, { value: 'O', label: 'O — خارج النطاق' }] },
            num('min_stock_alert', 'حد التنبيه للمخزون', { default: 5, show: (f) => f.type === 'product' }),
            { key: 'track_inventory', label: 'تتبع المخزون', type: 'checkbox', default: true, show: (f) => f.type === 'product' },
            num('initial_stock', 'الكمية الافتتاحية', { show: (f) => f.type === 'product' && f.track_inventory }),
            { key: 'warehouse_id', label: 'مستودع الرصيد الافتتاحي', type: 'select', lookup: 'warehouses', entity: 'warehouse', defaultFirst: true, show: (f) => f.type === 'product' && f.track_inventory && Number(f.initial_stock) > 0 },
            { key: 'description', label: 'الوصف', type: 'textarea', span: 2 },
        ],
    },
    supplier: {
        title: 'مورد جديد', route: 'purchases.suppliers.store', seed: 'name', size: 'lg', pick: pick('supplier'), label: (r) => r.name,
        fields: () => [
            text('name', 'اسم المورد', { required: true }),
            text('company_name', 'اسم المنشأة'),
            text('tax_number', 'الرقم الضريبي'),
            text('commercial_register', 'السجل التجاري'),
            text('phone', 'الهاتف'),
            { key: 'email', label: 'البريد الإلكتروني', type: 'email' },
            text('bank_name', 'اسم البنك'),
            text('iban', 'الآيبان'),
            num('opening_balance', 'الرصيد الافتتاحي (دائن)'),
            { key: 'address', label: 'العنوان', type: 'textarea', span: 2 },
        ],
    },
    bank: {
        title: 'حساب بنكي / خزينة جديد', route: 'finance.banks.store', seed: 'name',
        fields: () => [
            text('name', 'اسم الحساب', { required: true, span: 2 }),
            { key: 'type', label: 'النوع', type: 'select', options: [{ value: 'bank', label: 'حساب بنكي' }, { value: 'cash', label: 'خزينة نقدية' }], default: 'bank' },
            text('currency', 'العملة', { required: true, default: taxInfo().currency || 'SAR' }),
            text('bank_name', 'اسم البنك', { show: (f) => f.type === 'bank' }),
            text('account_number', 'رقم الحساب', { show: (f) => f.type === 'bank' }),
            text('iban', 'الآيبان', { show: (f) => f.type === 'bank' }),
            num('opening_balance', 'الرصيد الافتتاحي', { required: true }),
            { key: 'account_id', label: 'حساب الأستاذ (يُنشأ تلقائياً إن تُرك فارغاً)', type: 'select', lookup: 'accounts', query: { type: 'asset' }, empty: 'إنشاء حساب تلقائياً', span: 2 },
        ],
    },
    account: {
        title: 'حساب جديد في الدليل', route: 'accounting.accounts.store', seed: 'name_ar',
        fields: () => [
            text('code', 'رقم الحساب', { required: true }),
            text('name_ar', 'اسم الحساب (بالعربي)', { required: true }),
            text('name_en', 'الاسم (بالإنجليزي)'),
            { key: 'parent_id', label: 'الحساب الأب', type: 'select', lookup: 'accounts', query: { parents: 1 }, empty: 'بدون (حساب رئيسي)' },
            { key: 'type', label: 'النوع', type: 'select', default: 'expense', options: [{ value: 'asset', label: 'أصول' }, { value: 'liability', label: 'خصوم' }, { value: 'equity', label: 'حقوق ملكية' }, { value: 'revenue', label: 'إيرادات' }, { value: 'expense', label: 'مصروفات' }] },
            { key: 'nature', label: 'الطبيعة', type: 'select', default: 'debit', options: [{ value: 'debit', label: 'مدين' }, { value: 'credit', label: 'دائن' }] },
            { key: 'cash_flow_category', label: 'تصنيف التدفق النقدي', type: 'select', empty: 'غير مصنف', options: [{ value: 'operating', label: 'تشغيلي' }, { value: 'investing', label: 'استثماري' }, { value: 'financing', label: 'تمويلي' }] },
            { key: 'is_parent', label: 'حساب رئيسي (يضم حسابات فرعية)', type: 'checkbox', default: false },
            { key: 'requires_cost_center', label: 'يتطلب مركز تكلفة', type: 'checkbox', default: false },
        ],
        onChange: (f, key) => { if (key === 'type') f.nature = ['asset', 'expense'].includes(f.type) ? 'debit' : 'credit'; },
    },
    cost_center: {
        title: 'مركز تكلفة جديد', route: 'accounting.cost-centers.store', seed: 'name_ar',
        fields: () => [
            text('code', 'الرمز', { required: true }),
            text('name_ar', 'الاسم (بالعربي)', { required: true }),
            text('name_en', 'الاسم (بالإنجليزي)'),
            { key: 'parent_id', label: 'المركز الأب', type: 'select', lookup: 'cost_centers', entity: 'cost_center', empty: 'بدون' },
            { key: 'is_active', label: 'فعّال', type: 'checkbox', default: true },
        ],
    },
    department: {
        title: 'إدارة جديدة', route: 'hr.departments.store', seed: 'name',
        fields: () => [
            text('name', 'اسم الإدارة', { required: true, span: 2 }),
            { key: 'parent_id', label: 'الإدارة الأم', type: 'select', lookup: 'departments', entity: 'department', empty: 'بدون' },
            { key: 'cost_center_id', label: 'مركز التكلفة', type: 'select', lookup: 'cost_centers', entity: 'cost_center', empty: 'بدون' },
        ],
    },
    position: {
        title: 'مسمى وظيفي جديد', route: 'hr.positions.store', seed: 'title',
        fields: () => [text('title', 'المسمى الوظيفي', { required: true }), text('grade', 'الدرجة')],
    },
    shift: {
        title: 'وردية جديدة', route: 'hr.shifts.store', seed: 'name',
        fields: () => [
            text('name', 'اسم الوردية', { required: true, span: 2 }),
            { key: 'start_time', label: 'بداية الدوام', type: 'time', default: '08:00', required: true },
            { key: 'end_time', label: 'نهاية الدوام', type: 'time', default: '17:00', required: true },
            num('break_minutes', 'مدة الاستراحة (دقيقة)', { step: '1', default: 60 }),
            num('grace_minutes', 'سماح التأخير (دقيقة)', { step: '1', default: 15 }),
            { key: 'work_days', label: 'أيام العمل', type: 'days', default: [7, 1, 2, 3, 4], span: 2 },
            { key: 'is_default', label: 'وردية افتراضية', type: 'checkbox', default: false },
        ],
    },
    employee: {
        title: 'موظف جديد', route: 'hr.employees.store', seed: 'name', size: 'lg',
        fields: () => [
            text('name', 'اسم الموظف', { required: true }),
            text('employee_number', 'الرقم الوظيفي', { hint: 'يُولَّد تلقائياً إن ترك فارغاً' }),
            text('job_title', 'المسمى الوظيفي', { required: true }),
            { key: 'department_id', label: 'الإدارة', type: 'select', lookup: 'departments', entity: 'department', empty: 'بدون' },
            { key: 'position_id', label: 'الوظيفة', type: 'select', lookup: 'positions', entity: 'position', empty: 'بدون' },
            { key: 'branch_id', label: 'الفرع', type: 'select', lookup: 'branches', defaultFirst: true },
            text('nationality', 'الجنسية (رمز دولة)', { required: true, default: taxInfo().country || 'SA', maxlength: 2 }),
            { key: 'gender', label: 'الجنس', type: 'select', empty: '—', options: [{ value: 'M', label: 'ذكر' }, { value: 'F', label: 'أنثى' }] },
            { key: 'id_type', label: 'نوع الهوية', type: 'select', default: 'national_id', options: [{ value: 'national_id', label: 'هوية وطنية' }, { value: 'iqama', label: 'إقامة' }, { value: 'border_number', label: 'رقم حدود' }] },
            text('national_id', 'رقم الهوية'),
            text('phone', 'الجوال'),
            { key: 'email', label: 'البريد الإلكتروني', type: 'email' },
            { key: 'hire_date', label: 'تاريخ المباشرة', type: 'date', required: true, default: today() },
            { key: 'contract_type', label: 'نوع العقد', type: 'select', default: 'unlimited', options: [{ value: 'unlimited', label: 'غير محدد المدة' }, { value: 'limited', label: 'محدد المدة' }] },
            { key: 'contract_end', label: 'نهاية العقد', type: 'date', show: (f) => f.contract_type === 'limited', required: true },
            num('basic_salary', 'الراتب الأساسي', { required: true }),
            num('housing_allowance', 'بدل السكن'),
            num('transport_allowance', 'بدل النقل'),
            num('other_allowances', 'بدلات أخرى'),
            { key: 'status', label: 'الحالة', type: 'select', default: 'active', options: [{ value: 'active', label: 'على رأس العمل' }, { value: 'on_leave', label: 'في إجازة' }] },
        ],
    },
    customer_group: {
        title: 'مجموعة عملاء جديدة', route: 'sales.customer-groups.store', seed: 'name',
        fields: () => [
            text('code', 'رمز المجموعة', { required: true }),
            text('name', 'اسم المجموعة', { required: true }),
            { key: 'account_id', label: 'الحساب المحاسبي المرتبط', type: 'select', lookup: 'accounts', query: { type: 'asset' }, entity: 'account', empty: 'الذمم العامة', span: 2 },
            text('description', 'الوصف', { span: 2 }),
        ],
    },
    support_category: {
        title: 'تصنيف تذاكر جديد', route: 'support.settings.categories.store', seed: 'name', label: (r) => r.name,
        fields: () => [text('name', 'اسم التصنيف', { required: true, span: 2 })],
    },
    service_plan: {
        title: 'خطة اشتراك جديدة', route: 'sales.service-plans.store', seed: 'name', label: (r) => r.name,
        fields: () => [
            text('name', 'اسم الخطة', { required: true, span: 2 }),
            num('price', 'السعر لكل فترة', { required: true }),
            { key: 'cycle', label: 'دورة الفوترة', type: 'select', default: 'monthly', options: [{ value: 'monthly', label: 'شهري' }, { value: 'quarterly', label: 'ربع سنوي' }, { value: 'yearly', label: 'سنوي' }] },
            num('trial_days', 'أيام التجربة المجانية', { step: '1' }),
        ],
    },
    booking_resource: {
        title: 'مورد حجز جديد', route: 'bookings.resources.store', seed: 'name', label: (r) => r.name,
        fields: () => [
            text('name', 'الاسم', { required: true, span: 2 }),
            { key: 'type', label: 'النوع', type: 'select', default: 'staff', options: [{ value: 'staff', label: 'موظف' }, { value: 'room', label: 'غرفة' }, { value: 'equipment', label: 'معدة' }, { value: 'court', label: 'ملعب / قاعة' }] },
            num('capacity', 'عدد الحجوزات المتزامنة', { step: '1', default: 1 }),
        ],
    },
    bookable_service: {
        title: 'خدمة حجز جديدة', route: 'bookings.services.store', seed: 'name', label: (r) => r.name,
        fields: () => [
            text('name', 'اسم الخدمة', { required: true, span: 2 }),
            num('duration_minutes', 'المدة بالدقائق', { step: '5', default: 30, required: true }),
            num('price', 'السعر', { required: true }),
        ],
    },
    work_type: {
        title: 'نوع عمل جديد', route: 'work-orders.types.store', seed: 'name', label: (r) => r.name,
        fields: () => [
            text('name', 'اسم نوع العمل', { required: true, span: 2 }),
            num('hourly_rate', 'سعر الساعة للعميل', { required: true }),
        ],
    },
    nationality: {
        title: 'جنسية جديدة', route: 'hr.nationalities.store', seed: 'name_ar',
        fields: () => [
            text('name_ar', 'الجنسية بالعربي (مثال: مصري)', { required: true }),
            text('name_en', 'بالإنجليزي (Egyptian)'),
            text('code', 'رمز الدولة ISO2', { required: true, maxlength: 2, hint: 'حرفان مثل EG' }),
        ],
    },
    work_center: {
        title: 'مركز عمل جديد', route: 'manufacturing.work-centers.store', seed: 'name', size: 'lg',
        fields: () => [
            text('code', 'الرمز', { required: true }),
            text('name', 'الاسم', { required: true }),
            { key: 'type', label: 'النوع', type: 'select', default: 'machine', options: [{ value: 'machine', label: 'آلة' }, { value: 'line', label: 'خط إنتاج' }, { value: 'labour', label: 'عمالة' }, { value: 'workshop', label: 'ورشة' }] },
            { key: 'cost_center_id', label: 'مركز التكلفة', type: 'select', lookup: 'cost_centers', entity: 'cost_center', empty: 'بدون' },
            num('capacity_hours_per_day', 'الطاقة (ساعة/يوم)', { default: 8, required: true }),
            num('efficiency', 'الكفاءة %', { default: 100, required: true }),
            num('labour_rate_per_hour', 'تكلفة العمالة/ساعة', { required: true }),
            num('overhead_rate_per_hour', 'التكاليف غير المباشرة/ساعة', { required: true }),
            { key: 'is_active', label: 'فعّال', type: 'checkbox', default: true },
            { key: 'notes', label: 'ملاحظات', type: 'textarea', span: 2 },
        ],
    },
    asset_category: {
        title: 'فئة أصول جديدة', route: 'accounting.fixed-assets.categories.store', seed: 'name_ar',
        fields: () => [
            text('name_ar', 'اسم الفئة (بالعربي)', { required: true }),
            text('name_en', 'الاسم (بالإنجليزي)'),
            num('useful_life_months', 'العمر الإنتاجي (شهر)', { step: '1', default: 60, required: true }),
            { key: 'depreciation_method', label: 'طريقة الإهلاك', type: 'select', default: 'straight_line', options: [{ value: 'straight_line', label: 'القسط الثابت' }, { value: 'declining_balance', label: 'القسط المتناقص' }, { value: 'units_of_production', label: 'وحدات الإنتاج' }] },
            num('declining_rate', 'نسبة التناقص %', { show: (f) => f.depreciation_method === 'declining_balance' }),
        ],
    },
};

export const quickCreateRoute = (entity) => quickCreate[entity]?.route;
