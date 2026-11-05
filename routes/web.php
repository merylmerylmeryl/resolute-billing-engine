<?php

use App\Http\Controllers\ClientController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/clients');
Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
Route::get('/clients/{client:external_id}', [ClientController::class, 'show'])->name('clients.show');
