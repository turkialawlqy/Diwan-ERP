<?php

namespace App\Services\Projects;

use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\ProgressBilling;
use App\Models\Project;
use App\Models\ProjectBoqItem;
use App\Models\ProjectCost;
use App\Models\ProjectVariation;
use App\Services\Accounting\AccountMap;

/**
 * Project control figures: contract, billing, cost (ledger by cost centre
 * and by category), estimate vs actual, margin and cash position.
 */
class ProjectReport
{
    public function __construct(protected AccountMap $accounts) {}

    public function summary(Project $project): array
    {
        $cc = $project->cost_center_id;
        $ledger = fn (array $roles) => (float) JournalEntryLine::where('cost_center_id', $cc)
            ->whereIn('account_id', array_map(fn ($r) => $this->accounts->id($r), $roles))
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as v')->value('v');

        $revenue = -$ledger(['contract_revenue']);
        $cost = $ledger(['project_costs', 'subcontractor_costs']);

        $estimatedCost = (float) ProjectBoqItem::where('project_id', $project->id)->where('is_group', false)
            ->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as v')->value('v');
        $approvedVariations = (float) ProjectVariation::where('project_id', $project->id)->where('status', 'approved')->sum('amount');
        $billed = (float) ProgressBilling::where('project_id', $project->id)->where('status', 'approved')->sum('current_value');

        $invoiceIds = ProgressBilling::where('project_id', $project->id)->whereNotNull('invoice_id')->pluck('invoice_id');
        $collected = Invoice::whereIn('id', $invoiceIds)->sumBase('paid_amount') + (float) $project->advance_received * 1.15;

        $contract = (float) $project->contract_value;
        $progress = $contract > 0 ? round($billed / $contract * 100, 2) : 0;

        return [
            'contract_value' => $contract,
            'approved_variations' => $approvedVariations,
            'billed_to_date' => $billed,
            'remaining_to_bill' => round($contract - $billed, 2),
            'progress_percent' => $progress,
            'revenue' => round($revenue, 2),
            'actual_cost' => round($cost, 2),
            'estimated_cost' => round($estimatedCost, 2),
            'cost_variance' => round($estimatedCost - $cost, 2),
            'gross_profit' => round($revenue - $cost, 2),
            'margin_percent' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 2) : null,
            'projected_margin' => $contract > 0 ? round(($contract - $estimatedCost) / $contract * 100, 2) : null,
            'advance_open' => round((float) $project->advance_received - (float) $project->advance_recovered, 2),
            'retention_open' => round((float) $project->retention_held - (float) $project->retention_released, 2),
            'collected' => round($collected, 2),
            'costs_by_category' => ProjectCost::where('project_id', $project->id)
                ->selectRaw('category, SUM(amount) as total')->groupBy('category')->pluck('total', 'category')
                ->map(fn ($v) => round((float) $v, 2)),
        ];
    }
}
