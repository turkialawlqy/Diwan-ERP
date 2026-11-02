<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\ZatcaDocument;
use App\Services\TenantManager;
use App\Services\Zatca\EInvoiceManager;
use App\Services\Zatca\ZatcaClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Clears (standard) or reports (simplified) one signed document. Network
 * failures and 5xx are retried with back-off; 400 is a final rejection.
 * A document has one job waiting at a time: dispatching another for it while
 * the first has not started is skipped (the unique lock lasts $uniqueFor).
 */
class SubmitZatcaDocument implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    /** A job not started after this long (no worker, or lost) stops holding its document: erp:zatca-submit may add one more (6 h). */
    public int $uniqueFor = 21600;

    /**
     * Sent from the ZATCA page («إرسال الآن»، «إعادة الإرسال»، «إرسال المعلّق الآن»): a failed
     * document goes without waiting for its back-off. Not promoted, so a job queued
     * before it existed unserializes with the default.
     */
    public bool $manual = false;

    public function __construct(public int $documentId, public int $companyId, bool $manual = false)
    {
        $this->manual = $manual;
        $this->onQueue('zatca');
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    public function backoff(): array
    {
        return ZatcaDocument::RETRY_DELAYS;
    }

    public function handle(TenantManager $tenantManager, EInvoiceManager $manager): void
    {
        $company = Company::find($this->companyId);
        if (! $company) {
            return;
        }

        $tenantManager->runAs($company, function () use ($manager) {
            // Atomic claim: a document is never submitted by two workers at once. A failed one is
            // claimed only once its back-off is over, so another job for it cannot resend it at once.
            $claimed = ZatcaDocument::whereKey($this->documentId)
                ->when($this->manual, fn ($q) => $q->whereIn('status', ['pending', 'failed']), fn ($q) => $q->due())
                ->update(['status' => 'submitting', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            if (! $claimed) {
                return;
            }
            $record = ZatcaDocument::with('device')->find($this->documentId);
            $manager->resignIfStale($record);
            $client = ZatcaClient::for($record->device->environment);
            $credentials = $record->device->credentials();

            try {
                $response = $record->submission === 'clearance'
                    ? $client->clearance($credentials, $record->invoice_hash, $record->uuid, $record->signed_xml)
                    : $client->reporting($credentials, $record->invoice_hash, $record->uuid, $record->signed_xml);
            } catch (ConnectionException $e) {
                $record->forceFill(['status' => 'failed', 'errors' => [['message' => $e->getMessage()]]])->save();
                $this->release(ZatcaDocument::retryDelay($record->attempts));

                return;
            }

            $manager->applyResult($record, $response->status(), $response->json() ?? []);

            if ($record->fresh()->status === 'failed' && $this->attempts() < $this->tries) {
                $this->release(ZatcaDocument::retryDelay($record->attempts));
            }
        });
    }
}
