<?php

namespace App\Console\Commands;

use App\Services\AttachmentService;
use Illuminate\Console\Command;

class PruneStagedAttachments extends Command
{
    protected $signature = 'attachments:prune-staged {--hours=24}';

    protected $description = 'Delete files attached to documents that were never saved';

    public function handle(AttachmentService $attachments): int
    {
        $this->info('Pruned '.$attachments->pruneStaged((int) $this->option('hours')).' staged files.');

        return self::SUCCESS;
    }
}
