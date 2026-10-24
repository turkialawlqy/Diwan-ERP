<?php

namespace App\Services\EInvoicing;

use Illuminate\Database\Eloquent\Model;

/**
 * A country whose e-invoicing system is not integrated yet (or that has
 * none): documents are issued and printed normally, with nothing submitted.
 */
class PlannedProvider implements Provider
{
    public function __construct(protected ?string $system = null) {}

    public const LABELS = [
        'eta' => 'منظومة الفاتورة الإلكترونية — مصلحة الضرائب المصرية',
        'jofotara' => 'نظام الفوترة الوطني «جو فوترة» — الأردن',
        'uae' => 'الفوترة الإلكترونية — الهيئة الاتحادية للضرائب (الإمارات)',
        'fawtara' => 'منظومة «فوترة» — جهاز الضرائب (عُمان)',
    ];

    public function key(): string
    {
        return $this->system ?? 'none';
    }

    public function label(): string
    {
        return $this->system ? (self::LABELS[$this->system] ?? $this->system).' (الربط قيد التطوير)' : 'لا يوجد نظام فوترة إلكترونية حكومي';
    }

    public function integrated(): bool
    {
        return false;
    }

    public function stamp(Model $document): void
    {
        $document->forceFill(['zatca_status' => 'not_applicable'])->saveQuietly();
    }
}
