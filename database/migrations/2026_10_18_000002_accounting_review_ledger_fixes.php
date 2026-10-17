<?php

use App\Models\Company;
use App\Services\Accounting\AccountingSetupService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('fixed_assets', 'opening_accumulated')) {
                $table->decimal('opening_accumulated', 18, 2)->default(0)->after('accumulated_depreciation');
            }
            if (! Schema::hasColumn('fixed_assets', 'acquisition_vat')) {
                $table->decimal('acquisition_vat', 18, 2)->default(0)->after('cost');
            }
            if (! Schema::hasColumn('fixed_assets', 'disposal_vat')) {
                $table->decimal('disposal_vat', 18, 2)->default(0)->after('disposal_amount');
            }
        });

        $this->reclassifyEquipmentRecovery();
    }

    /**
     * Equipment charged to projects was a revenue account; it is a contra-expense
     * (the running costs move into project costs). An unused account is
     * reclassified in place; one with postings keeps its history and the role
     * moves to a new expense account.
     */
    protected function reclassifyEquipmentRecovery(): void
    {
        $settings = DB::table('company_settings')->where('key', 'acc_equipment_recovery_id')->where('value', '!=', '')->get();

        foreach ($settings as $setting) {
            $account = DB::table('accounts')->where('company_id', $setting->company_id)->where('id', (int) $setting->value)->first();
            if (! $account || $account->type === 'expense') {
                continue;
            }

            if (! DB::table('journal_entry_lines')->where('account_id', $account->id)->exists()) {
                $parent = DB::table('accounts')->where('company_id', $setting->company_id)->where('code', '5')->where('type', 'expense')->first()
                    ?? DB::table('accounts')->where('company_id', $setting->company_id)->whereNull('parent_id')->where('type', 'expense')->first();
                $codeTaken = DB::table('accounts')->where('company_id', $setting->company_id)->where('code', '59')->where('id', '!=', $account->id)->exists();

                DB::table('accounts')->where('id', $account->id)->update([
                    'type' => 'expense',
                    'nature' => 'credit',
                    'parent_id' => $parent?->id ?? $account->parent_id,
                    'code' => $codeTaken ? $account->code : '59',
                    'name_ar' => 'تكاليف معدات محمّلة على المشاريع',
                    'name_en' => 'Equipment Costs Charged to Projects',
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('company_settings')->where('id', $setting->id)->update(['value' => '', 'updated_at' => now()]);
            if ($company = Company::find($setting->company_id)) {
                app(AccountingSetupService::class)->provision($company);
            }
        }
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn(['opening_accumulated', 'acquisition_vat', 'disposal_vat']);
        });
    }
};
