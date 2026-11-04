<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Invoices a subscription issues outside its regular run (an upgrade) point back to it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_subscription_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['customer_subscription_id']);
            $table->dropColumn('customer_subscription_id');
        });
    }
};
