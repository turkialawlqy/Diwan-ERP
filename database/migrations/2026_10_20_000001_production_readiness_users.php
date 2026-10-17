<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Email verification is now required: accounts that already exist are trusted.
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);

        // POS history must survive a user being removed.
        if (DB::getDriverName() === 'mysql') {
            foreach (['pos_sessions', 'pos_orders'] as $table) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropForeign(['user_id']);
                    $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
                });
            }
        }

        if (! Schema::hasColumn('users', 'terms_accepted_at')) {
            Schema::table('users', fn (Blueprint $t) => $t->timestamp('terms_accepted_at')->nullable());
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['pos_sessions', 'pos_orders'] as $table) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropForeign(['user_id']);
                    $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                });
            }
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('terms_accepted_at'));
    }
};
