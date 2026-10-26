<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZATCA phase 2 (integration): e-invoice generation units (EGS) with their
 * keys and CSIDs, and every signed document with its hash chain and the
 * clearance / reporting outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('serial_number'); // 1-Solution|2-Model|3-UUID
            $table->string('environment', 20)->default('sandbox'); // sandbox, simulation, production
            $table->string('invoice_types', 4)->default('1100'); // standard + simplified
            $table->string('status', 20)->default('new'); // new, compliance, active, revoked
            $table->text('private_key')->nullable(); // encrypted
            $table->text('csr')->nullable();
            $table->text('compliance_token')->nullable(); // encrypted
            $table->text('compliance_secret')->nullable(); // encrypted
            $table->string('compliance_request_id')->nullable();
            $table->text('production_token')->nullable(); // encrypted
            $table->text('production_secret')->nullable(); // encrypted
            $table->timestamp('certificate_expires_at')->nullable();
            $table->unsignedBigInteger('icv')->default(0); // invoice counter value
            $table->string('last_hash', 100)->nullable(); // previous invoice hash (PIH)
            $table->json('compliance_checks')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('zatca_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zatca_device_id')->constrained()->restrictOnDelete();
            $table->string('documentable_type', 100);
            $table->unsignedBigInteger('documentable_id');
            $table->string('document_number', 50);
            $table->string('type_code', 3); // 388 invoice, 381 credit, 383 debit
            $table->string('subtype', 7); // 0100000 standard, 0200000 simplified
            $table->uuid('uuid');
            $table->unsignedBigInteger('icv');
            $table->string('previous_hash', 100);
            $table->string('invoice_hash', 100);
            $table->longText('signed_xml');
            $table->longText('cleared_xml')->nullable(); // returned by ZATCA on clearance
            $table->text('qr')->nullable();
            $table->string('submission', 20); // clearance, reporting
            $table->string('status', 20)->default('pending'); // pending, cleared, reported, warning, rejected, failed
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['zatca_device_id', 'icv']);
            $table->index(['documentable_type', 'documentable_id']);
            $table->index(['company_id', 'status']);
        });

        // zatca_status was an enum that does not match the phase 2 life cycle.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('zatca_status_new', 20)->default('not_submitted');
        });
        DB::table('invoices')->update(['zatca_status_new' => DB::raw('zatca_status')]);
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('zatca_status'));
        Schema::table('invoices', fn (Blueprint $table) => $table->renameColumn('zatca_status_new', 'zatca_status'));

        foreach (['credit_notes', 'debit_notes'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->string('zatca_status', 20)->default('not_submitted');
                $table->string('zatca_hash', 100)->nullable();
                if ($name === 'credit_notes' && ! Schema::hasColumn('credit_notes', 'zatca_uuid')) {
                    $table->uuid('zatca_uuid')->nullable();
                }
            });
        }
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['company_id', 'zatca_status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropIndex(['company_id', 'zatca_status']));
        foreach (['credit_notes', 'debit_notes'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['zatca_status', 'zatca_hash']));
        }
        Schema::dropIfExists('zatca_documents');
        Schema::dropIfExists('zatca_devices');
    }
};
