<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PrintTemplate;
use App\Services\Documents\FreeformLayout;
use App\Services\Documents\FreeformPresets;
use App\Services\Documents\FreeformRenderer;
use App\Services\Documents\FreeformTemplates;
use App\Services\Documents\TemplateData;
use App\Services\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Free-form designer: elements placed anywhere on the page. Preview data
 * comes from a real document of the template's kind (or sample values), and
 * the PDF preview renders the unsaved layout exactly as printing will.
 */
class FreeformTemplateController extends Controller
{
    public function designer(PrintTemplate $template): Response
    {
        abort_unless(FreeformTemplates::isFreeform($template), 404);
        $company = app(TenantManager::class)->getCompany();
        $kind = $template->template_type;

        return Inertia::render('Templates/FreeformDesigner', [
            'template' => $template->only(['id', 'name', 'template_type', 'is_default']),
            'layout' => FreeformLayout::sanitize($template->layout_data['freeform'] ?? [], $template->company_id),
            'catalogue' => TemplateData::catalogue($kind),
            'documents' => $this->recentDocuments($kind),
            'preview' => $this->previewData($kind, $company, null),
            'fonts' => collect(FreeformLayout::FONTS)->map(fn ($f, $key) => ['key' => $key, 'label' => $f[0], 'url' => route('templates.fonts', $key), 'bold' => $f[2] ? route('templates.fonts', [$key, 'bold' => 1]) : null])->values(),
            'papers' => FreeformLayout::PAPERS,
            'logo' => $company->logoPath() ? route('company.logo') : null,
            'kinds' => collect(TemplateData::KINDS)->map(fn ($k) => $k[0]),
        ]);
    }

    /** Field values of one real document (JSON, for the canvas). */
    public function data(Request $request, PrintTemplate $template): JsonResponse
    {
        abort_unless(FreeformTemplates::isFreeform($template), 404);

        return response()->json($this->previewData($template->template_type, app(TenantManager::class)->getCompany(), $request->integer('document') ?: null));
    }

    /** PDF of the layout being edited (not saved yet). */
    public function preview(Request $request, PrintTemplate $template)
    {
        abort_unless(FreeformTemplates::isFreeform($template), 404);
        $request->validate(['freeform' => 'required|array', 'document' => 'nullable|integer']);
        $company = app(TenantManager::class)->getCompany();
        $layout = FreeformLayout::sanitize((array) $request->input('freeform'), $company->id);
        $data = $this->previewData($template->template_type, $company, $request->integer('document') ?: null);

        return response(app(FreeformRenderer::class)->render($layout, $data, $company, [], 'معاينة '.$template->name), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Starting layout of the kind, to start over. */
    public function preset(PrintTemplate $template): JsonResponse
    {
        abort_unless(FreeformTemplates::isFreeform($template), 404);

        return response()->json(FreeformLayout::sanitize(FreeformPresets::for($template->template_type), $template->company_id));
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['image' => 'required|file|mimes:png,jpg,jpeg,gif|max:4096'], [], ['image' => 'الصورة']);
        $companyId = app(TenantManager::class)->getTenantId();
        $file = $request->file('image');
        $ext = strtolower($file->getClientOriginalExtension()) === 'jpeg' ? 'jpg' : strtolower($file->guessExtension() ?: 'png');
        $path = $file->storeAs('templates/'.$companyId, Str::random(24).'.'.(in_array($ext, ['png', 'jpg', 'gif'], true) ? $ext : 'png'), 'public');

        return response()->json(['src' => $path, 'url' => route('templates.image', basename($path))]);
    }

    /** Images uploaded for this company's templates (works without storage:link). */
    public function image(string $file)
    {
        $path = 'templates/'.app(TenantManager::class)->getTenantId().'/'.$file;
        abort_unless(preg_match('/^[A-Za-z0-9_-]{8,80}\.(png|jpe?g|gif)$/', $file) && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** The PDF fonts, so the canvas measures text like the printout does. */
    public function font(Request $request, string $font): BinaryFileResponse
    {
        abort_unless(isset(FreeformLayout::FONTS[$font]), 404);
        $file = FreeformLayout::FONTS[$font][$request->boolean('bold') && FreeformLayout::FONTS[$font][2] ? 2 : 1];
        $path = base_path('vendor/mpdf/mpdf/ttfonts/'.$file);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'font/ttf', 'Cache-Control' => 'public, max-age=2592000']);
    }

    protected function previewData(string $kind, Company $company, ?int $documentId): array
    {
        $class = TemplateData::KINDS[$kind][1];
        $document = $documentId ? $class::find($documentId) : $class::latest('id')->first();

        $data = $document ? app(TemplateData::class)->build($document) : app(TemplateData::class)->sample($kind, $company);
        $data['source'] = $document ? ['id' => $document->id, 'number' => $data['fields']['doc.number'] ?? ''] : null;

        return $data;
    }

    protected function recentDocuments(string $kind): array
    {
        $class = TemplateData::KINDS[$kind][1];
        $number = ['invoice' => 'invoice_number', 'quotation' => 'quotation_number', 'sales_order' => 'order_number', 'credit_note' => 'credit_note_number',
            'debit_note' => 'debit_note_number', 'receipt' => 'payment_number', 'payment' => 'payment_number'][$kind];

        return $class::latest('id')->limit(30)->get(['id', $number])->map(fn ($d) => ['id' => $d->id, 'number' => $d->{$number}])->all();
    }
}
