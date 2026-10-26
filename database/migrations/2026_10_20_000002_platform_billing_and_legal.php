<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Subscription invoices are the platform's own tax invoices.
        if (! Schema::hasColumn('subscription_invoices', 'qr')) {
            Schema::table('subscription_invoices', function (Blueprint $t) {
                $t->text('qr')->nullable();
                $t->timestamp('issued_at')->nullable();
            });
        }

        // Terms and privacy policy shown at signup (editable by the platform admin).
        if (! Schema::hasTable('platform_pages')) {
            Schema::create('platform_pages', function (Blueprint $t) {
                $t->id();
                $t->string('slug', 40)->unique();
                $t->string('title');
                $t->longText('body');
                $t->foreignId('updated_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_pages');
        Schema::table('subscription_invoices', fn (Blueprint $t) => $t->dropColumn(['qr', 'issued_at']));
    }
};
