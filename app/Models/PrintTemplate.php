<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintTemplate extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'template_type',
        'name',
        'primary_color',
        'show_logo',
        'show_qr',
        'show_stamp',
        'show_bank_details',
        'terms_and_conditions',
        'footer_text',
        'layout_data',
        'is_default',
    ];

    protected $casts = [
        'show_logo' => 'boolean',
        'show_qr' => 'boolean',
        'show_stamp' => 'boolean',
        'show_bank_details' => 'boolean',
        'is_default' => 'boolean',
        'layout_data' => 'array',
    ];

    /**
     * Get default layout data configuration.
     */
    public static function getDefaultLayoutData(): array
    {
        return [
            'design' => [
                'primary_color' => '#1e293b',
                'secondary_color' => '#64748b',
                'font_family' => 'Cairo',
                'font_size_base' => 12,
                'border_style' => 'solid',
            ],
            'page' => [
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'margin_top' => 15,
                'margin_bottom' => 15,
                'margin_left' => 15,
                'margin_right' => 15,
                'show_page_number' => true,
            ],
            'logo' => [
                'show' => true,
                'position' => 'right',
                'height' => 85,
                'max_width' => 280,
            ],
            'qr' => [
                'show' => true,
                'position' => 'left',
                'size' => 100,
            ],
            'header' => [
                'layout_order' => 'logo_meta_qr',
                'show_divider_line' => true,
                'title' => [
                    'text' => 'فاتورة ضريبية',
                    'tag' => '{{zatca.invoice.title.ar}}',
                    'font_size' => 14,
                    'color' => '#0f172a',
                    'alignment' => 'center',
                    'is_bold' => true,
                ],
                'meta_fields' => [
                    ['id' => 'code', 'label' => 'الرقم', 'tag' => '{{invoice.code}}', 'font_size' => 12, 'color' => '#0f172a', 'alignment' => 'right', 'is_bold' => true],
                    ['id' => 'date', 'label' => 'التاريخ', 'tag' => '{{invoice.issue_date}}', 'font_size' => 12, 'color' => '#0f172a', 'alignment' => 'right', 'is_bold' => false],
                    ['id' => 'ref', 'label' => 'الرقم المرجعي', 'tag' => '{{invoice.partner_reference_code}}', 'font_size' => 12, 'color' => '#0f172a', 'alignment' => 'right', 'is_bold' => false],
                    ['id' => 'payment_status', 'label' => 'حالة الدفع', 'tag' => '{{invoice.payments.status.ar}}', 'font_size' => 12, 'color' => '#0f172a', 'alignment' => 'right', 'is_bold' => false],
                    ['id' => 'payment_method', 'label' => 'طريقة الدفع', 'tag' => '{{invoice.payments.payment_method_names}}', 'font_size' => 12, 'color' => '#0f172a', 'alignment' => 'right', 'is_bold' => false],
                ],
            ],
            'company' => [
                'show_name' => true,
                'show_vat' => true,
                'show_cr' => true,
                'show_address' => true,
                'show_phone' => true,
                'show_email' => false,
            ],
            'customer' => [
                'show_name' => true,
                'show_vat' => true,
                'show_address' => true,
                'show_phone' => true,
                'show_ref' => true,
            ],
            'table' => [
                'header_bg' => '#f8fafc',
                'header_text_color' => '#1e293b',
                'columns' => [
                    ['key' => 'index', 'label' => 'البند', 'visible' => true, 'width' => '10%', 'align' => 'center'],
                    ['key' => 'description', 'label' => 'الوصف', 'visible' => true, 'width' => '40%', 'align' => 'right'],
                    ['key' => 'unit_price', 'label' => 'السعر', 'visible' => true, 'width' => '15%', 'align' => 'center'],
                    ['key' => 'quantity', 'label' => 'الكمية', 'visible' => true, 'width' => '15%', 'align' => 'center'],
                    ['key' => 'total', 'label' => 'المجموع', 'visible' => true, 'width' => '20%', 'align' => 'center'],
                ],
            ],
            'footer' => [
                'show_subtotal' => true,
                'show_vat_15' => true,
                'show_grand_total' => true,
                'show_paid' => true,
                'show_due' => true,
                'show_tafqeet' => true,
                'show_bank_details' => true,
                'show_terms' => true,
                'show_notes' => true,
                'show_signature' => true,
                'show_stamp' => true,
            ],
        ];
    }
}
