<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Input VAT is only reclaimable on a tax invoice from a VAT-registered supplier.
        if (! Schema::hasColumn('expenses', 'vendor_tax_number')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->string('vendor_tax_number', 15)->nullable()->after('vendor_name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('vendor_tax_number');
        });
    }
};
