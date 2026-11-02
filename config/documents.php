<?php

/*
|--------------------------------------------------------------------------
| Document Numbering
|--------------------------------------------------------------------------
|
| Every numbered document type. Numbers are "{prefix}{YY}-{sequence}" and the
| sequence restarts every year when "yearly" is true. A company may override
| the prefix in Settings ← «دليل الترميز» (numbering_rules); the values here are
| the defaults. label / area / module only feed that guide.
|
*/

return [
    'types' => [
        'sales_invoice' => ['table' => 'invoices', 'column' => 'invoice_number', 'prefix' => 'INV-', 'pad' => 6, 'yearly' => true, 'label' => 'فاتورة مبيعات', 'area' => 'sales'],
        'pos_invoice' => ['table' => 'invoices', 'column' => 'invoice_number', 'prefix' => 'SIM-', 'pad' => 6, 'yearly' => true, 'label' => 'فاتورة مبسطة (نقاط البيع)', 'area' => 'sales', 'module' => 'pos'],
        'quotation' => ['table' => 'quotations', 'column' => 'quotation_number', 'prefix' => 'QUO-', 'pad' => 6, 'yearly' => true, 'label' => 'عرض سعر', 'area' => 'sales'],
        'credit_note' => ['table' => 'credit_notes', 'column' => 'credit_note_number', 'prefix' => 'CN-', 'pad' => 6, 'yearly' => true, 'label' => 'إشعار دائن', 'area' => 'sales'],
        'customer_payment' => ['table' => 'customer_payments', 'column' => 'payment_number', 'prefix' => 'REC-', 'pad' => 6, 'yearly' => true, 'label' => 'سند قبض من عميل', 'area' => 'sales'],
        'pos_order' => ['table' => 'pos_orders', 'column' => 'order_number', 'prefix' => 'POS-', 'pad' => 6, 'yearly' => true, 'label' => 'طلب نقطة بيع', 'area' => 'sales', 'module' => 'pos'],
        'purchase_order' => ['table' => 'purchase_orders', 'column' => 'order_number', 'prefix' => 'PO-', 'pad' => 6, 'yearly' => true, 'label' => 'أمر شراء', 'area' => 'purchases'],
        'purchase_invoice' => ['table' => 'purchase_invoices', 'column' => 'invoice_number', 'prefix' => 'BILL-', 'pad' => 6, 'yearly' => true, 'label' => 'فاتورة مشتريات', 'area' => 'purchases'],
        'supplier_payment' => ['table' => 'supplier_payments', 'column' => 'payment_number', 'prefix' => 'SPAY-', 'pad' => 6, 'yearly' => true, 'label' => 'سند صرف لمورد', 'area' => 'purchases'],
        'journal_entry' => ['table' => 'journal_entries', 'column' => 'entry_number', 'prefix' => 'JV-', 'pad' => 6, 'yearly' => true, 'label' => 'قيد يومية', 'area' => 'accounting'],
        'expense' => ['table' => 'expenses', 'column' => 'expense_number', 'prefix' => 'EXP-', 'pad' => 6, 'yearly' => true, 'label' => 'سند صرف', 'area' => 'accounting'],
        'revenue' => ['table' => 'revenues', 'column' => 'revenue_number', 'prefix' => 'REV-', 'pad' => 6, 'yearly' => true, 'label' => 'سند قبض', 'area' => 'accounting'],
        'payroll' => ['table' => 'payrolls', 'column' => 'payroll_number', 'prefix' => 'PAY-', 'pad' => 5, 'yearly' => true, 'label' => 'مسير رواتب', 'area' => 'parties', 'module' => 'hr'],
        'transfer' => ['table' => null, 'column' => null, 'prefix' => 'TRF-', 'pad' => 6, 'yearly' => true, 'label' => 'تحويل بين الحسابات', 'area' => 'accounting'],
        'stock_transfer' => ['table' => 'stock_transfers', 'column' => 'transfer_number', 'prefix' => 'TRN-', 'pad' => 6, 'yearly' => true, 'label' => 'تحويل مخزون', 'area' => 'inventory'],
        'stock_count' => ['table' => 'stock_counts', 'column' => 'count_number', 'prefix' => 'CNT-', 'pad' => 5, 'yearly' => true, 'label' => 'جرد مخزون', 'area' => 'inventory'],
        'production_order' => ['table' => 'production_orders', 'column' => 'order_number', 'prefix' => 'MO-', 'pad' => 5, 'yearly' => true, 'label' => 'أمر إنتاج', 'area' => 'work', 'module' => 'manufacturing'],
        'subcontract_order' => ['table' => 'subcontract_orders', 'column' => 'order_number', 'prefix' => 'SUB-', 'pad' => 5, 'yearly' => true, 'label' => 'أمر تصنيع خارجي', 'area' => 'work', 'module' => 'manufacturing'],
        'qc_inspection' => ['table' => 'qc_inspections', 'column' => 'inspection_number', 'prefix' => 'QC-', 'pad' => 5, 'yearly' => true, 'label' => 'فحص جودة', 'area' => 'work', 'module' => 'manufacturing'],
        'assembly_order' => ['table' => 'assembly_orders', 'column' => 'order_number', 'prefix' => 'ASM-', 'pad' => 5, 'yearly' => true, 'label' => 'أمر تجميع', 'area' => 'inventory'],
        'sales_order' => ['table' => 'sales_orders', 'column' => 'order_number', 'prefix' => 'SO-', 'pad' => 6, 'yearly' => true, 'label' => 'أمر بيع', 'area' => 'sales'],
        'delivery_note' => ['table' => 'delivery_notes', 'column' => 'delivery_number', 'prefix' => 'DN-', 'pad' => 6, 'yearly' => true, 'label' => 'إذن تسليم', 'area' => 'sales'],
        'debit_note' => ['table' => 'debit_notes', 'column' => 'debit_note_number', 'prefix' => 'DBN-', 'pad' => 6, 'yearly' => true, 'label' => 'إشعار مدين', 'area' => 'sales'],
        'purchase_request' => ['table' => 'purchase_requests', 'column' => 'request_number', 'prefix' => 'PR-', 'pad' => 6, 'yearly' => true, 'label' => 'طلب شراء', 'area' => 'purchases'],
        'goods_receipt' => ['table' => 'goods_receipts', 'column' => 'receipt_number', 'prefix' => 'GRN-', 'pad' => 6, 'yearly' => true, 'label' => 'استلام بضاعة', 'area' => 'purchases'],
        'purchase_return' => ['table' => 'purchase_returns', 'column' => 'return_number', 'prefix' => 'PRT-', 'pad' => 6, 'yearly' => true, 'label' => 'مرتجع مشتريات', 'area' => 'purchases'],
        'landed_cost' => ['table' => 'landed_costs', 'column' => 'landed_number', 'prefix' => 'LC-', 'pad' => 5, 'yearly' => true, 'label' => 'تكاليف شحن', 'area' => 'purchases'],
        'subscription' => ['table' => 'customer_subscriptions', 'column' => 'number', 'prefix' => 'SBS-', 'pad' => 5, 'yearly' => false, 'label' => 'اشتراك عميل', 'area' => 'sales'],
        'service_plan' => ['table' => 'service_plans', 'column' => 'code', 'prefix' => 'PLN-', 'pad' => 4, 'yearly' => false, 'label' => 'خطة خدمة (اشتراكات)', 'area' => 'sales'],
        'ticket' => ['table' => 'tickets', 'column' => 'number', 'prefix' => 'TKT-', 'pad' => 5, 'yearly' => true, 'label' => 'تذكرة دعم', 'area' => 'sales', 'module' => 'support'],
        'contract' => ['table' => 'contracts', 'column' => 'number', 'prefix' => 'CTR-', 'pad' => 5, 'yearly' => true, 'label' => 'عقد', 'area' => 'sales', 'module' => 'contracts'],
        'customer' => ['table' => 'customers', 'column' => 'code', 'prefix' => 'C-', 'pad' => 5, 'yearly' => false, 'label' => 'كود العميل', 'area' => 'parties'],
        'supplier' => ['table' => 'suppliers', 'column' => 'code', 'prefix' => 'S-', 'pad' => 5, 'yearly' => false, 'label' => 'كود المورد', 'area' => 'parties'],
        'employee' => ['table' => 'employees', 'column' => 'employee_number', 'prefix' => 'EMP-', 'pad' => 4, 'yearly' => false, 'label' => 'رقم الموظف', 'area' => 'parties', 'module' => 'hr'],
        'project' => ['table' => 'projects', 'column' => 'code', 'prefix' => 'PRJ-', 'pad' => 4, 'yearly' => false, 'label' => 'كود المشروع', 'area' => 'work', 'module' => 'projects'],
        'subcontract' => ['table' => 'subcontracts', 'column' => 'number', 'prefix' => 'SUB-', 'pad' => 5, 'yearly' => true, 'label' => 'عقد مقاول باطن', 'area' => 'work', 'module' => 'projects'],
        'equipment_rental' => ['table' => 'equipment_rentals', 'column' => 'number', 'prefix' => 'ER-', 'pad' => 5, 'yearly' => true, 'label' => 'تأجير معدة', 'area' => 'work', 'module' => 'equipment'],
        'maintenance_order' => ['table' => 'maintenance_orders', 'column' => 'number', 'prefix' => 'MO-', 'pad' => 5, 'yearly' => true, 'label' => 'أمر صيانة', 'area' => 'work', 'module' => 'equipment'],
        'product' => ['table' => 'products', 'column' => 'sku', 'prefix' => 'PRD-', 'pad' => 5, 'yearly' => false, 'label' => 'كود الصنف', 'area' => 'inventory'],
    ],

    /*
    | PDF output (mPDF). The default font ships with mPDF and shapes Arabic
    | correctly; put another TTF in resources/fonts and set PDF_FONT_FILE to
    | use it (e.g. Cairo-Regular.ttf with PDF_FONT=cairo).
    */
    'pdf' => [
        'font' => env('PDF_FONT', 'xbriyaz'),
        'font_file' => env('PDF_FONT_FILE'),
    ],

    // Signed links sent to customers (PDF download, account statement).
    'share_link_days' => (int) env('DOCUMENT_LINK_DAYS', 30),
];
