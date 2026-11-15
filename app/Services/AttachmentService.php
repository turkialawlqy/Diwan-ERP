<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Staged attachments: files uploaded while a document was being written, claimed by the document when it is saved. */
class AttachmentService
{
    /**
     * Attach the staged files named in `attachment_ids` to the saved document. Only files the same user staged for the same
     * kind of document, in this company, that nobody claimed yet are taken; anything else is ignored.
     */
    public function claim(Request $request, Model $document, string $kind): int
    {
        $ids = collect((array) $request->input('attachment_ids', []))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->take(20)->values();
        if ($ids->isEmpty() || ! $request->user()) {
            return 0;
        }

        return Attachment::whereIn('id', $ids)->whereNull('attachable_id')->where('stage_kind', $kind)->where('uploaded_by', $request->user()->id)
            ->update(['attachable_type' => $document->getMorphClass(), 'attachable_id' => $document->getKey(), 'stage_kind' => null]);
    }

    /** Delete staged files nobody claimed (the document was never saved). */
    public function pruneStaged(int $hours = 24): int
    {
        $count = 0;
        Attachment::withoutGlobalScopes()->whereNull('attachable_id')->where('created_at', '<', now()->subHours($hours))->get()->each(function (Attachment $a) use (&$count) {
            Storage::disk('local')->delete($a->path);
            $a->delete();
            $count++;
        });

        return $count;
    }
}
