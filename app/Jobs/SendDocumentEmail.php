<?php

namespace App\Jobs;

use App\Mail\DocumentMail;
use App\Models\Company;
use App\Models\DocumentDispatch;
use App\Services\Documents\DocumentSharing;
use App\Services\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Renders the document PDF inside its company's tenant context and emails it.
 * The dispatch row records the outcome so the user sees "sent" or the error.
 */
class SendDocumentEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $dispatchId) {}

    public function handle(TenantManager $tenants, DocumentSharing $sharing): void
    {
        $dispatch = DocumentDispatch::withoutGlobalScopes()->find($this->dispatchId);
        if (! $dispatch || $dispatch->status === 'sent') {
            return;
        }
        $company = Company::findOrFail($dispatch->company_id);

        $tenants->runAs($company, function () use ($dispatch, $company, $sharing) {
            $document = $sharing->find($dispatch->documentable_type, $dispatch->documentable_id);
            $pdf = $sharing->pdf($document);
            $customer = $sharing->customer($document);
            $def = DocumentSharing::TYPES[$dispatch->documentable_type];

            $mail = new DocumentMail(
                subjectLine: $def['label'].' '.$sharing->number($document).' — '.$company->name,
                body: $dispatch->message,
                companyName: $company->name,
                replyToAddress: $company->email,
                pdf: $pdf['content'],
                filename: $pdf['filename'],
                link: $customer ? $sharing->portalLink($customer) : null,
            );

            $pending = Mail::to($dispatch->recipient);
            if ($dispatch->cc) {
                $pending->cc(array_map('trim', explode(',', $dispatch->cc)));
            }
            $pending->send($mail);

            $dispatch->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        });
    }

    public function failed(Throwable $e): void
    {
        DocumentDispatch::withoutGlobalScopes()->whereKey($this->dispatchId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
    }
}
