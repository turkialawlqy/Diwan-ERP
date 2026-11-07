<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PaymentRequest;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Revenue;
use App\Models\SalesOrder;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to documents. The document is looked up through its own scoped model (company, branch), and its own
 * view permission is checked besides «admin.attachments.*»; files live on the private disk and download as attachments.
 */
class AttachmentController extends Controller
{
    /** kind => [model, permission that lets a user see that document] */
    public const KINDS = [
        'invoice' => [Invoice::class, 'sales.invoices.view'],
        'purchase-invoice' => [PurchaseInvoice::class, 'purchases.invoices.view'],
        'expense' => [Expense::class, 'finance.expenses.view'],
        'revenue' => [Revenue::class, 'finance.revenues.view'],
        'journal' => [JournalEntry::class, 'accounting.journals.view'],
        'quotation' => [Quotation::class, 'sales.quotations.view'],
        'sales-order' => [SalesOrder::class, 'sales.orders.view'],
        'purchase-order' => [PurchaseOrder::class, 'purchases.orders.view'],
        'payment-request' => [PaymentRequest::class, 'finance.payment_requests.view'],
    ];

    public const MAX_KB = 10240;

    /** Extensions accepted: documents, images and spreadsheets only (no html / svg / scripts). */
    public const MIMES = 'pdf,jpg,jpeg,png,webp,gif,doc,docx,xls,xlsx,csv,txt';

    protected function document(Request $request, string $kind, int $id)
    {
        abort_unless(isset(self::KINDS[$kind]), 404);
        [$model, $permission] = self::KINDS[$kind];
        abort_unless($request->user()->can($permission), 403);

        return $model::findOrFail($id);
    }

    public function index(Request $request, string $kind, int $id)
    {
        $document = $this->document($request, $kind, $id);

        return response()->json(['data' => Attachment::where('attachable_type', $document->getMorphClass())->where('attachable_id', $document->getKey())
            ->orderByDesc('id')->get(['id', 'original_name', 'mime', 'size', 'created_at'])
            ->map(fn ($a) => $a->toArray() + ['url' => route('attachments.download', $a->id)])]);
    }

    public function store(Request $request, string $kind, int $id)
    {
        $document = $this->document($request, $kind, $id);
        $request->validate(['file' => 'required|file|max:'.self::MAX_KB.'|mimes:'.self::MIMES]);

        $file = $request->file('file');
        $stored = FileController::storeForTenant($file, 'attachments');
        $attachment = Attachment::create([
            'attachable_type' => $document->getMorphClass(), 'attachable_id' => $document->getKey(), 'path' => $stored['path'],
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => $file->getClientMimeType(), 'size' => $file->getSize(), 'uploaded_by' => auth()->id(),
        ]);

        return response()->json(['data' => $attachment->only(['id', 'original_name', 'mime', 'size', 'created_at']) + ['url' => route('attachments.download', $attachment->id)]], 201);
    }

    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->authorizeDocument($request, $attachment);
        $tenantId = app(TenantManager::class)->getTenantId();
        abort_unless($tenantId && str_starts_with($attachment->path, "companies/{$tenantId}/") && Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"]);
    }

    public function destroy(Request $request, Attachment $attachment)
    {
        $this->authorizeDocument($request, $attachment);
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        return response()->json(['ok' => true]);
    }

    /** The user must be able to see the document the file hangs on. */
    protected function authorizeDocument(Request $request, Attachment $attachment): void
    {
        $kind = collect(self::KINDS)->search(fn ($k) => (new $k[0])->getMorphClass() === $attachment->attachable_type);
        abort_unless($kind !== false, 404);
        [$model, $permission] = self::KINDS[$kind];
        abort_unless($request->user()->can($permission) && $model::whereKey($attachment->attachable_id)->exists(), 403);
    }
}
