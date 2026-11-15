<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files can be attached while a document is still being written: the file is uploaded «staged» (no document yet, only the
 * kind it is meant for and who uploaded it) and claimed by the document when it is saved. Unclaimed files are pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('attachable_type', 100)->nullable()->change();
            $table->unsignedBigInteger('attachable_id')->nullable()->change();
            $table->string('stage_kind', 40)->nullable()->after('attachable_id');
            $table->index(['company_id', 'stage_kind', 'uploaded_by']);
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'stage_kind', 'uploaded_by']);
            $table->dropColumn('stage_kind');
        });
    }
};
