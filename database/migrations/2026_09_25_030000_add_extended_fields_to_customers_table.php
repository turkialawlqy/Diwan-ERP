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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_type')->default('commercial')->after('company_id'); // individual, commercial
            $table->string('code')->nullable()->after('customer_type');
            $table->string('contact_name')->nullable()->after('company_name');
            $table->string('mobile')->nullable()->after('phone');
            $table->string('building_number')->nullable()->after('address');
            $table->string('street_name')->nullable()->after('building_number');
            $table->string('district')->nullable()->after('street_name');
            $table->string('province')->nullable()->after('city');
            $table->string('additional_details')->nullable()->after('district');
            $table->string('country')->default('المملكة العربية السعودية')->after('province');
            $table->json('additional_contacts')->nullable()->after('notes');
            $table->json('attachments')->nullable()->after('additional_contacts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'customer_type',
                'code',
                'contact_name',
                'mobile',
                'building_number',
                'street_name',
                'district',
                'province',
                'additional_details',
                'country',
                'additional_contacts',
                'attachments',
            ]);
        });
    }
};
