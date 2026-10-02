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
        'customer' => ['table' => 'customers', 'column' => 'code', 'prefix' => 'C-', 'pad' => 5, 'yearly' => false],
        'product' => ['table' => 'products', 'column' => 'sku', 'prefix' => 'PRD-', 'pad' => 5, 'yearly' => false],
    ],
];
