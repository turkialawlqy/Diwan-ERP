<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval requests for actions that have no «pending approval» status of their own (a payment, an expense,
 * a journal, a payroll...). The action is refused until an approver accepts the request; a retry then passes once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('subject_key', 64);          // the document (type|id) or a hash of the payload for a document not saved yet
            $table->string('description', 255);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, used
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'document_type', 'subject_key', 'status'], 'approval_requests_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
