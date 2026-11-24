<?php

use App\Http\Controllers\Api\SyncController;
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

/*
| Offline branches: a branch server's calls to the head office (node token, 404 unless this server is the hub). Not a user session,
| so it has no permission map; SyncHub allows a node to write only what it made, in its own company.
*/
Route::prefix('sync')->name('api.sync.')->middleware(['throttle:240,1', 'sync.node'])->group(function () {
    Route::post('/handshake', [SyncController::class, 'handshake'])->name('handshake');
    Route::post('/push', [SyncController::class, 'push'])->name('push');
    Route::post('/pull', [SyncController::class, 'pull'])->name('pull');
});
