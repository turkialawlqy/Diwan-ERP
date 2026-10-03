<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('company_id');
            $table->string('phone')->nullable()->after('email');
            $table->string('avatar')->nullable()->after('phone');
            $table->unsignedBigInteger('branch_id')->nullable()->after('avatar');
            $table->enum('status', ['active', 'inactive', 'banned'])->default('active')->after('branch_id');
            $table->string('google_id')->nullable()->after('password');
            $table->timestamp('last_login_at')->nullable()->after('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn([
                'company_id',
                'is_super_admin',
                'phone',
                'avatar',
                'branch_id',
                'status',
                'google_id',
                'last_login_at',
            ]);
        });
    }
};
