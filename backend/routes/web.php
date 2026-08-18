<?php

use App\Http\Controllers\Api\Director\PaymentRequestSupportingDocumentController;
use App\Http\Controllers\DealerController;
use App\Http\Controllers\Inventory\StockItemLedgerPrintController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::resource('dealers', DealerController::class);

    Route::get('/inventory/stock-item-ledger/print', StockItemLedgerPrintController::class)
        ->name('inventory.stock-item-ledger.print');

    Route::get(
        '/payment-requests/{paymentRequest}/supporting-documents/{supportingDocument}',
        [PaymentRequestSupportingDocumentController::class, 'show']
    )->name('payment-requests.supporting-documents.show');

});

require __DIR__.'/auth.php';
