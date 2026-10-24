<?php

use App\Http\Controllers\CustomerController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The customer form once had a single "building number and street" field
 * saved as the street, leaving the building number empty, which ZATCA tax
 * invoices require. Streets that start with a 4-digit number give it to
 * the building number.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')
            ->where(fn ($q) => $q->whereNull('building_number')->orWhere('building_number', ''))
            ->whereNotNull('street_name')
            ->orderBy('id')
            ->each(function ($customer) {
                [$building, $street] = CustomerController::splitBuildingNumber(null, $customer->street_name);
                if ($building) {
                    DB::table('customers')->where('id', $customer->id)->update(['building_number' => $building, 'street_name' => $street]);
                }
            });
    }

    public function down(): void
    {
        // The data was corrected; nothing to undo.
    }
};
