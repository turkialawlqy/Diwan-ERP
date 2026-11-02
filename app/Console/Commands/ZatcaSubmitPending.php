<?php

namespace App\Console\Commands;

use App\Jobs\SubmitZatcaDocument;
use App\Models\ZatcaDocument;
use Illuminate\Console\Command;

/**
 * Safety net: re-queues signed documents still pending / failed (e.g. the
 * queue was down) so simplified invoices meet the 24h reporting window.
 */
class ZatcaSubmitPending extends Command
{
    protected $signature = 'erp:zatca-submit {--minutes=5 : Only documents waiting this long (failed ones: this long past their back-off)}';

    protected $description = 'Queue pending or failed ZATCA clearance / reporting submissions';

    public function handle(): int
    {
        $count = 0;
        ZatcaDocument::withoutGlobalScopes()
            ->where('attempts', '<', 20)
            // A failed document waits out its back-off: the job that failed it retries then, and this only steps in after the grace.
            ->where(fn ($q) => $q->due((int) $this->option('minutes') * 60)
                // A worker that died mid-submission leaves "submitting" behind.
                ->orWhere(fn ($q) => $q->where('status', 'submitting')->where('updated_at', '<', now()->subMinutes(15))))
            ->orderBy('id')
            ->select(['id', 'company_id'])
            ->chunkById(500, function ($documents) use (&$count) {
                // updated_at stays: the back-off counts from the attempt that got stuck.
                ZatcaDocument::withoutGlobalScopes()->whereIn('id', $documents->pluck('id'))->where('status', 'submitting')->toBase()->update(['status' => 'failed']);
                foreach ($documents as $document) {
                    // Unique per document: not queued again while an earlier job for it has not started.
                    SubmitZatcaDocument::dispatch($document->id, $document->company_id);
                    $count++;
                }
            });

        $this->info("{$count} document(s) due; each is queued unless a job for it is already waiting.");

        return self::SUCCESS;
    }
}
