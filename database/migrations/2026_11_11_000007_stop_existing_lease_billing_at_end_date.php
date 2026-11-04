<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Leases made before the fix had no end on their rent billing: it now stops at the lease end like new ones. */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('leases')) {
            return;
        }
        DB::table('leases')->where('status', 'active')->whereNotNull('subscription_id')->orderBy('id')->get(['subscription_id', 'end_date'])->each(function ($lease) {
            $templateId = DB::table('customer_subscriptions')->where('id', $lease->subscription_id)->value('recurring_invoice_id');
            if ($templateId && $lease->end_date) {
                DB::table('recurring_invoices')->where('id', $templateId)->whereNull('end_date')->update(['end_date' => Carbon::parse($lease->end_date)->toDateString()]);
            }
        });
    }

    public function down(): void
    {
        // Kept: billing past the lease end was the defect.
    }
};
