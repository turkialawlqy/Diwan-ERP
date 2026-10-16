<?php

/*
|--------------------------------------------------------------------------
| Document Numbering
|--------------------------------------------------------------------------
|
| Every numbered document type. Numbers are "{prefix}{YY}-{sequence}" and the
| sequence restarts every year when "yearly" is true. A company may override
| the prefix with a "{type}_prefix" company setting.
|
*/

return [
    'types' => [
        'sales_invoice' => ['table' => 'invoices', 'column' => 'invoice_number', 'prefix' => 'INV-', 'pad' => 6, 'yearly' => true],
        'pos_invoice' => ['table' => 'invoices', 'column' => 'invoice_number', 'prefix' => 'SIM-', 'pad' => 6, 'yearly' => true],
        'quotation' => ['table' => 'quotations', 'column' => 'quotation_number', 'prefix' => 'QUO-', 'pad' => 6, 'yearly' => true],
        'credit_note' => ['table' => 'credit_notes', 'column' => 'credit_note_number', 'prefix' => 'CN-', 'pad' => 6, 'yearly' => true],
        'customer_payment' => ['table' => 'customer_payments', 'column' => 'payment_number', 'prefix' => 'REC-', 'pad' => 6, 'yearly' => true],
        'pos_order' => ['table' => 'pos_orders', 'column' => 'order_number', 'prefix' => 'POS-', 'pad' => 6, 'yearly' => true],
        'purchase_order' => ['table' => 'purchase_orders', 'column' => 'order_number', 'prefix' => 'PO-', 'pad' => 6, 'yearly' => true],
        'purchase_invoice' => ['table' => 'purchase_invoices', 'column' => 'invoice_number', 'prefix' => 'BILL-', 'pad' => 6, 'yearly' => true],
        'supplier_payment' => ['table' => 'supplier_payments', 'column' => 'payment_number', 'prefix' => 'SPAY-', 'pad' => 6, 'yearly' => true],
        'journal_entry' => ['table' => 'journal_entries', 'column' => 'entry_number', 'prefix' => 'JV-', 'pad' => 6, 'yearly' => true],
        'expense' => ['table' => 'expenses', 'column' => 'expense_number', 'prefix' => 'EXP-', 'pad' => 6, 'yearly' => true],
        'revenue' => ['table' => 'revenues', 'column' => 'revenue_number', 'prefix' => 'REV-', 'pad' => 6, 'yearly' => true],
        'payroll' => ['table' => 'payrolls', 'column' => 'payroll_number', 'prefix' => 'PAY-', 'pad' => 5, 'yearly' => true],
        'transfer' => ['table' => null, 'column' => null, 'prefix' => 'TRF-', 'pad' => 6, 'yearly' => true],
        'stock_transfer' => ['table' => 'stock_transfers', 'column' => 'transfer_number', 'prefix' => 'TRN-', 'pad' => 6, 'yearly' => true],
        'stock_count' => ['table' => 'stock_counts', 'column' => 'count_number', 'prefix' => 'CNT-', 'pad' => 5, 'yearly' => true],
        'assembly_order' => ['table' => 'assembly_orders', 'column' => 'order_number', 'prefix' => 'ASM-', 'pad' => 5, 'yearly' => true],
        'sales_order' => ['table' => 'sales_orders', 'column' => 'order_number', 'prefix' => 'SO-', 'pad' => 6, 'yearly' => true],
        'delivery_note' => ['table' => 'delivery_notes', 'column' => 'delivery_number', 'prefix' => 'DN-', 'pad' => 6, 'yearly' => true],
        'debit_note' => ['table' => 'debit_notes', 'column' => 'debit_note_number', 'prefix' => 'DBN-', 'pad' => 6, 'yearly' => true],
        'purchase_request' => ['table' => 'purchase_requests', 'column' => 'request_number', 'prefix' => 'PR-', 'pad' => 6, 'yearly' => true],
        'goods_receipt' => ['table' => 'goods_receipts', 'column' => 'receipt_number', 'prefix' => 'GRN-', 'pad' => 6, 'yearly' => true],
        'purchase_return' => ['table' => 'purchase_returns', 'column' => 'return_number', 'prefix' => 'PRT-', 'pad' => 6, 'yearly' => true],
        'landed_cost' => ['table' => 'landed_costs', 'column' => 'landed_number', 'prefix' => 'LC-', 'pad' => 5, 'yearly' => true],
        'customer' => ['table' => 'customers', 'column' => 'code', 'prefix' => 'C-', 'pad' => 5, 'yearly' => false],
        'employee' => ['table' => 'employees', 'column' => 'employee_number', 'prefix' => 'EMP-', 'pad' => 4, 'yearly' => false],
        'project' => ['table' => 'projects', 'column' => 'code', 'prefix' => 'PRJ-', 'pad' => 4, 'yearly' => false],
        'subcontract' => ['table' => 'subcontracts', 'column' => 'number', 'prefix' => 'SUB-', 'pad' => 5, 'yearly' => true],
        'equipment_rental' => ['table' => 'equipment_rentals', 'column' => 'number', 'prefix' => 'ER-', 'pad' => 5, 'yearly' => true],
        'maintenance_order' => ['table' => 'maintenance_orders', 'column' => 'number', 'prefix' => 'MO-', 'pad' => 5, 'yearly' => true],
        'product' => ['table' => 'products', 'column' => 'sku', 'prefix' => 'PRD-', 'pad' => 5, 'yearly' => false],
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
