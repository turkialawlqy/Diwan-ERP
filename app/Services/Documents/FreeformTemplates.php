<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\PrintTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * Picks the free-form layout a document prints with: the template asked for,
 * else the company's default free-form template of that kind, else the
 * built-in starting layout for kinds that have no quick layout.
 */
class FreeformTemplates
{
    /** Kinds that always print free-form (no quick designer layout exists). */
    public const FREEFORM_ONLY = ['sales_order', 'debit_note', 'receipt', 'payment'];

    /** Template types whose "default" flag is shared: one default per group. */
    public const GROUPS = ['invoice' => ['invoice', 'invoice_a4']];

    public static function isFreeform(?PrintTemplate $template): bool
    {
        return $template && (($template->layout_data['mode'] ?? null) === 'freeform');
    }

    public static function group(string $type): array
    {
        foreach (self::GROUPS as $members) {
            if (in_array($type, $members, true)) {
                return $members;
            }
        }

        return [$type];
    }

    /** @return array|null sanitized layout */
    public function resolve(Model $document, ?int $templateId = null): ?array
    {
        $kind = TemplateData::kindOf($document);
        $query = PrintTemplate::withoutGlobalScopes()->where('company_id', $document->company_id)->where('template_type', $kind);

        $template = $templateId ? (clone $query)->find($templateId) : null;
        $template ??= (clone $query)->where('is_default', true)->get()->first(fn ($t) => self::isFreeform($t));
        // Without a chosen default, a kind that has only free-form templates uses its first one.
        if (! $template && in_array($kind, self::FREEFORM_ONLY, true)) {
            $template = (clone $query)->orderBy('id')->get()->first(fn ($t) => self::isFreeform($t));
        }

        if (self::isFreeform($template)) {
            return FreeformLayout::sanitize($template->layout_data['freeform'] ?? [], $document->company_id);
        }

        return in_array($kind, self::FREEFORM_ONLY, true) ? FreeformLayout::sanitize(FreeformPresets::for($kind), $document->company_id) : null;
    }

    public function render(Model $document, array $layout, array $attachments, string $title): string
    {
        $company = Company::findOrFail($document->company_id);

        return app(FreeformRenderer::class)->render($layout, app(TemplateData::class)->build($document), $company, $attachments, $title);
    }
}
