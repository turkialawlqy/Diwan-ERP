<?php

namespace App\Services\Projects;

use App\Models\ProgressBilling;
use App\Models\Project;
use App\Models\ProjectBoqItem;
use App\Models\ProjectWipEntry;
use App\Services\Accounting\PostingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revenue by progress. The executed quantity of each BOQ line (work done, certified or not) × its selling rate is
 * the revenue earned; what the approved billings already recognised is subtracted. A positive difference is a
 * contract asset (earned, not yet billed), a negative one a contract liability (billed ahead of the work).
 * The adjustment is posted at a period end and reversed automatically on the next day.
 */
class ProjectWip
{
    public function __construct(protected PostingService $posting) {}

    public function figures(Project $project): array
    {
        $items = ProjectBoqItem::where('project_id', $project->id)->where('is_group', false)->get(['quantity', 'unit_price', 'executed_quantity']);
        $contract = round((float) $items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_price), 2);
        $earned = round((float) $items->sum(fn ($i) => (float) $i->executed_quantity * (float) $i->unit_price), 2);
        $billed = round((float) ProgressBilling::where('project_id', $project->id)->where('status', 'approved')->sum('current_value'), 2);
        $wip = round($earned - $billed, 2);

        return [
            'contract_value' => $contract, 'earned' => $earned, 'billed' => $billed, 'wip' => $wip,
            'percent' => $contract > 0 ? round($earned / $contract * 100, 2) : 0.0,
            'asset' => max($wip, 0.0), 'liability' => max(-$wip, 0.0),
            'posted' => ProjectWipEntry::where('project_id', $project->id)->where('status', 'posted')->latest('date')->first(),
        ];
    }

    /** @param array<int, array{boq_item_id: int, executed_quantity: float}> $lines */
    public function updateProgress(Project $project, array $lines): void
    {
        DB::transaction(function () use ($project, $lines) {
            foreach ($lines as $line) {
                $item = ProjectBoqItem::where('project_id', $project->id)->where('is_group', false)->whereKey($line['boq_item_id'])->lockForUpdate()->first();
                if (! $item) {
                    throw ValidationException::withMessages(['lines' => 'أحد البنود لا يخص المشروع.']);
                }
                $executed = round((float) $line['executed_quantity'], 4);
                if ($executed < (float) $item->billed_quantity - 0.0001) {
                    throw ValidationException::withMessages(['lines' => "الكمية المنفذة للبند {$item->code} أقل من المعتمد في المستخلصات ({$item->billed_quantity})."]);
                }
                if ($executed > (float) $item->quantity + 0.0001) {
                    throw ValidationException::withMessages(['lines' => "الكمية المنفذة للبند {$item->code} تتجاوز كمية العقد ({$item->quantity})."]);
                }
                $item->update(['executed_quantity' => $executed]);
            }
        });
    }

    /** Post the contract asset / liability at $date; it is reversed on the following day (one adjustment per date). */
    public function post(Project $project, string $date): ?ProjectWipEntry
    {
        return DB::transaction(function () use ($project, $date) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $day = Carbon::parse($date);

            if (ProjectWipEntry::where('project_id', $project->id)->whereDate('date', $day->toDateString())->exists()) {
                throw ValidationException::withMessages(['date' => 'رُحِّلت تسوية لهذا التاريخ مسبقًا؛ اختر تاريخ نهاية فترة آخر.']);
            }

            $f = $this->figures($project);
            if (abs($f['wip']) < 0.005) {
                return null;
            }

            $entry = ProjectWipEntry::create(['project_id' => $project->id, 'branch_id' => $project->branch_id, 'date' => $day->toDateString(), 'earned' => $f['earned'], 'billed' => $f['billed'], 'amount' => $f['wip'], 'created_by' => auth()->id()]);
            $cc = $project->cost_center_id;
            $amount = abs($f['wip']);
            $journal = $this->posting->post([
                'date' => $day,
                'reference' => $project->code,
                'description' => "تسوية الإيراد بالإنجاز (WIP) لمشروع {$project->code} حتى {$day->toDateString()}",
                'source' => $entry,
                'lines' => $f['wip'] > 0
                    ? [['role' => 'contract_asset', 'debit' => $amount, 'cost_center_id' => $cc], ['role' => 'contract_revenue', 'credit' => $amount, 'cost_center_id' => $cc]]
                    : [['role' => 'contract_revenue', 'debit' => $amount, 'cost_center_id' => $cc], ['role' => 'contract_liability', 'credit' => $amount, 'cost_center_id' => $cc]],
            ]);
            $this->posting->reverse($journal, $day->copy()->addDay(), "عكس تلقائي - تسوية WIP {$project->code}");

            return $entry;
        });
    }
}
