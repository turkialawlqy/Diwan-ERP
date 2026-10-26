<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\ZatcaDocument;
use App\Services\TenantManager;
use App\Services\Zatca\EInvoiceManager;
use App\Services\Zatca\ZatcaClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Clears (standard) or reports (simplified) one signed document. Network
 * failures and 5xx are retried with back-off; 400 is a final rejection.
 */
class SubmitZatcaDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public int $documentId, public int $companyId)
    {
        $this->onQueue('zatca');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(TenantManager $tenantManager, EInvoiceManager $manager): void
    {
        $company = Company::find($this->companyId);
        if (! $company) {
            return;
        }

        $tenantManager->runAs($company, function () use ($manager) {
            // Atomic claim: a document is never submitted by two workers at once.
            $claimed = ZatcaDocument::whereKey($this->documentId)->whereIn('status', ['pending', 'failed'])
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
                $this->release($this->backoff()[min($this->attempts() - 1, 4)]);

                return;
            }

            $manager->applyResult($record, $response->status(), $response->json() ?? []);

            if ($record->fresh()->status === 'failed' && $this->attempts() < $this->tries) {
                $this->release($this->backoff()[min($this->attempts() - 1, 4)]);
            }
        });
    }
}
