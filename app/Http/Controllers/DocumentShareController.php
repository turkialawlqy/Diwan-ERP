<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentDispatch;
use App\Models\Invoice;
use App\Services\Documents\DocumentSharing;
use App\Services\Sales\CustomerStatement;
use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF download and "send to customer" for invoices, credit notes, quotations,
 * progress billings and account statements, plus the signed public links a
 * customer opens without logging in.
 */
class DocumentShareController extends Controller
{
    public function __construct(protected DocumentSharing $sharing) {}

    public function pdf(Request $request, string $type, int $id): Response
    {
        $document = $this->authorized($request, $type, $id);

        return $this->pdfResponse($document, $request->boolean('download'), $request->integer('template') ?: null);
    }

    public function send(Request $request, string $type, int $id): JsonResponse
    {
        $document = $this->authorized($request, $type, $id);
        $validated = $request->validate([
            'channel' => 'required|in:email,whatsapp',
            'to' => 'required_if:channel,email|nullable|email|max:255',
            'cc' => ['nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                foreach (array_filter(array_map('trim', explode(',', (string) $value))) as $email) {
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $fail('عنوان النسخة غير صحيح: '.$email);
                    }
                }
            }],
            'phone' => 'required_if:channel,whatsapp|nullable|string|max:30',
            'message' => 'nullable|string|max:3000',
        ], [], ['to' => 'البريد الإلكتروني', 'cc' => 'النسخة', 'phone' => 'رقم الجوال', 'message' => 'نص الرسالة']);

        if ($validated['channel'] === 'email') {
            $dispatch = $this->sharing->email($document, $validated['to'], $validated['cc'] ?? null, $validated['message'] ?? null, $request->user()->id);

            return response()->json(['dispatch' => $dispatch->fresh(), 'message' => $dispatch->fresh()->status === 'sent' ? 'تم إرسال البريد' : 'تمت جدولة الإرسال']);
        }

        $result = $this->sharing->whatsapp($document, $validated['phone'], $validated['message'] ?? null, $request->user()->id);

        return response()->json(['dispatch' => $result['dispatch'], 'url' => $result['url']]);
    }

    /** Defaults for the send dialog plus the dispatch history. */
    public function dispatches(Request $request, string $type, int $id): JsonResponse
    {
        $document = $this->authorized($request, $type, $id);
        $customer = $this->sharing->customer($document);

        return response()->json([
            'defaults' => [
                'to' => $customer?->email,
                'phone' => $customer?->mobile ?: $customer?->phone,
                'message' => $this->sharing->message($document),
            ],
            'history' => DocumentDispatch::with('user:id,name')
                ->where('documentable_type', $type)->where('documentable_id', $id)
                ->latest()->limit(50)->get(),
        ]);
    }

    /** Public: signed link to a document PDF. */
    public function sharedPdf(Request $request, int $company, string $type, int $id): Response
    {
        // Plain id (no route-model binding) so the signature is checked before
        // anything is looked up: a forged link never reveals what exists.
        $company = Company::findOrFail($company);

        return app(TenantManager::class)->runAs($company, fn () => $this->pdfResponse($this->sharing->find($type, $id), false));
    }

    /** Public: signed link to a customer's statement and invoices. */
    public function portal(Request $request, int $company, int $customer)
    {
        $company = Company::findOrFail($company);

        return app(TenantManager::class)->runAs($company, function () use ($company, $customer, $request) {
            $customer = Customer::findOrFail($customer);
            if ($request->boolean('pdf')) {
                return $this->pdfResponse($customer, false);
            }

            $statement = app(CustomerStatement::class)->build($customer);
            $invoices = Invoice::where('customer_id', $customer->id)->latest('invoice_date')->limit(100)
                ->get(['id', 'company_id', 'invoice_number', 'invoice_date', 'due_date', 'total', 'paid_amount', 'remaining_amount', 'payment_status'])
                ->map(fn ($inv) => $inv->setAttribute('pdf_url', $this->sharing->pdfLink($inv)));

            return response()->view('portal.statement', [
                'company' => $company,
                // Inline: the logo route needs a login, the customer has none.
                'logo' => ($path = $company->logoPath())
                    ? 'data:'.Storage::disk('public')->mimeType($path).';base64,'.base64_encode(Storage::disk('public')->get($path))
                    : null,
                'customer' => $customer,
                'statement' => $statement,
                'invoices' => $invoices,
                // Signed on its own: adding ?pdf=1 to the page URL would break its signature.
                'statementPdf' => $this->sharing->portalLink($customer, true),
            ])->header('X-Robots-Tag', 'noindex, nofollow');
        });
    }

    protected function authorized(Request $request, string $type, int $id): Model
    {
        abort_unless(isset(DocumentSharing::TYPES[$type]), 404);
        abort_unless($request->user()->can(DocumentSharing::TYPES[$type]['permission']), 403, 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');

        return $this->sharing->find($type, $id);
    }

    protected function pdfResponse(Model $document, bool $download, ?int $templateId = null): Response
    {
        $pdf = $this->sharing->pdf($document, $templateId);

        return response($pdf['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$pdf['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
