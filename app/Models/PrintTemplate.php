<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintTemplate extends Model
{
    use HasFactory, BelongsToCompany;

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
        'is_default',
    ];

    protected $casts = [
        'show_logo' => 'boolean',
        'show_qr' => 'boolean',
        'show_stamp' => 'boolean',
        'show_bank_details' => 'boolean',
        'is_default' => 'boolean',
    ];
}
