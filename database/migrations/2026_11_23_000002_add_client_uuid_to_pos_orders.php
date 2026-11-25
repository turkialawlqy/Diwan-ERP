<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A sale made on the terminal without a connection: its own id (so a retry never records it twice) and when it was really made. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pos_orders', 'client_uuid')) {
            Schema::table('pos_orders', function (Blueprint $t) {
                $t->uuid('client_uuid')->nullable()->after('order_number');
                $t->dateTime('sold_at')->nullable()->after('client_uuid');
                $t->unique(['company_id', 'client_uuid'], 'pos_orders_company_client_uuid_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_orders', 'client_uuid')) {
            Schema::table('pos_orders', function (Blueprint $t) {
                $t->dropUnique('pos_orders_company_client_uuid_unique');
                $t->dropColumn(['client_uuid', 'sold_at']);
            });
        }
    }
};
