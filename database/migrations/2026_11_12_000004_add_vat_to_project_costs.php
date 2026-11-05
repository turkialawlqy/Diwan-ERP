<?php

use App\Models\CompanySetting;
use App\Models\ProjectCost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Input VAT on a direct project cost is kept on the cost, so the VAT return (built from documents) can include it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_costs', function (Blueprint $table) {
            $table->decimal('vat_amount', 14, 2)->default(0);
        });
        // Costs recorded before: the VAT is in their journal entry's input-VAT line (the account is per company).
        $morph = (new ProjectCost)->getMorphClass();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $account = CompanySetting::withoutGlobalScopes()->where('company_id', $companyId)->where('key', 'acc_vat_input_id')->value('value');
            if (! $account || ! is_numeric($account)) {
                continue;
            }
            $rows = DB::table('journal_entry_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.company_id', $companyId)->where('e.source_type', $morph)->where('l.account_id', (int) $account)
                ->selectRaw('e.source_id as cost_id, SUM(l.debit) as vat')->groupBy('e.source_id')->get();
            foreach ($rows as $r) {
                DB::table('project_costs')->where('id', $r->cost_id)->update(['vat_amount' => $r->vat]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('project_costs', function (Blueprint $table) {
            $table->dropColumn('vat_amount');
        });
    }
};
