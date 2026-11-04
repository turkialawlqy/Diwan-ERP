<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A booking deposit is a real receipt (customer payment): applied to the invoice when done, kept as credit or forfeited when cancelled. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('deposit_payment_id')->nullable()->after('deposit_amount')->constrained('customer_payments')->nullOnDelete();
            $table->string('deposit_status', 20)->nullable()->after('deposit_payment_id'); // held | applied | credited | forfeited
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_payment_id');
            $table->dropColumn('deposit_status');
        });
    }
};
