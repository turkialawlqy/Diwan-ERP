<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Customer portal: separate login accounts for customers, one-time codes, contract acceptances. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('status', 10)->default('active'); // active, disabled
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'email']);
            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('portal_login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['portal_user_id', 'used_at']);
        });

        Schema::create('contract_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portal_user_id')->nullable()->constrained('portal_users')->nullOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('version');
            $table->string('body_hash', 64);
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->unique(['contract_id', 'version', 'portal_user_id'], 'contract_acceptance_unique');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('shared_with_customer')->default(false)->after('attachments');
            $table->boolean('requires_acceptance')->default(false)->after('shared_with_customer');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('portal_user_id')->nullable()->after('created_by')->constrained('portal_users')->nullOnDelete();
        });
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->foreignId('portal_user_id')->nullable()->after('user_id')->constrained('portal_users')->nullOnDelete();
        });

        // Plans that sell sales also get the portal module (null modules = every module already).
        foreach (DB::table('subscription_plans')->whereNotNull('modules')->get(['id', 'modules']) as $plan) {
            $modules = json_decode($plan->modules, true) ?: [];
            if (in_array('sales', $modules, true) && ! in_array('portal', $modules, true)) {
                $modules[] = 'portal';
                DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode($modules)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('ticket_messages', fn (Blueprint $t) => $t->dropConstrainedForeignId('portal_user_id'));
        Schema::table('tickets', fn (Blueprint $t) => $t->dropConstrainedForeignId('portal_user_id'));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn(['shared_with_customer', 'requires_acceptance']));
        Schema::dropIfExists('contract_acceptances');
        Schema::dropIfExists('portal_login_codes');
        Schema::dropIfExists('portal_users');
    }
};
