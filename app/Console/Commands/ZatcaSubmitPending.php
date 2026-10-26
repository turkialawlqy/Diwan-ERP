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
    protected $signature = 'erp:zatca-submit {--minutes=5 : Only documents older than this}';

    protected $description = 'Queue pending or failed ZATCA clearance / reporting submissions';

    public function handle(): int
    {
        $count = 0;
        ZatcaDocument::withoutGlobalScopes()
            ->where('attempts', '<', 20)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('status', ['pending', 'failed'])->where('updated_at', '<', now()->subMinutes((int) $this->option('minutes'))))
                // A worker that died mid-submission leaves "submitting" behind.
                ->orWhere(fn ($q) => $q->where('status', 'submitting')->where('updated_at', '<', now()->subMinutes(15))))
            ->orderBy('id')
            ->select(['id', 'company_id'])
            ->chunkById(500, function ($documents) use (&$count) {
                ZatcaDocument::withoutGlobalScopes()->whereIn('id', $documents->pluck('id'))->where('status', 'submitting')->update(['status' => 'failed']);
                foreach ($documents as $document) {
                    SubmitZatcaDocument::dispatch($document->id, $document->company_id);
                    $count++;
                }
            });

        $this->info("Queued {$count} document(s).");

        return self::SUCCESS;
    }
}
