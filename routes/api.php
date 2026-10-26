<?php

use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Support\Facades\Route;

/*
| REST API v1 — Sanctum personal access tokens. The company is the token
| owner's; each route needs the matching permission on the user AND the token.
*/
Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'tenant.resolve', 'tenant', 'plan', 'erp.authorize', 'throttle:api'])->group(function () {
    Route::get('/me', [ApiController::class, 'me'])->name('me');
    Route::get('/customers', [ApiController::class, 'customers'])->name('customers.index');
    Route::post('/customers', [ApiController::class, 'storeCustomer'])->name('customers.store');
    Route::get('/customers/{customer}', [ApiController::class, 'customer'])->name('customers.show');
    Route::get('/products', [ApiController::class, 'products'])->name('products.index');
    Route::get('/products/{product}', [ApiController::class, 'product'])->name('products.show');
    Route::get('/invoices', [ApiController::class, 'invoices'])->name('invoices.index');
    Route::post('/invoices', [ApiController::class, 'storeInvoice'])->name('invoices.store');
    Route::get('/invoices/{invoice}', [ApiController::class, 'invoice'])->name('invoices.show');
});
