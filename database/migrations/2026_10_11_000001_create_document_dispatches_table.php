<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every time a document is sent or shared with a customer.
        Schema::create('document_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('documentable_type', 40);
            $table->unsignedBigInteger('documentable_id');
            $table->string('channel', 20); // email, whatsapp, link
            $table->string('recipient')->nullable();
            $table->string('cc')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('queued'); // queued, sent, failed
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'documentable_type', 'documentable_id'], 'doc_dispatch_doc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_dispatches');
    }
};
