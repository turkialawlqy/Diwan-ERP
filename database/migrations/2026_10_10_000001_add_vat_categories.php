<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VAT category (S standard, Z zero rated, E exempt, O out of scope) and the
 * ZATCA exemption reason per product, kept on document lines as issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('vat_category', 1)->default('S');
            $table->string('vat_exemption_code', 30)->nullable();
        });
        foreach (['invoice_items', 'credit_note_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('vat_category', 1)->nullable();
                $table->string('vat_exemption_code', 30)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['products', 'invoice_items', 'credit_note_items'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn(['vat_category', 'vat_exemption_code']));
        }
    }
};
