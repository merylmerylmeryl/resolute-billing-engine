<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/clients');
Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
Route::get('/clients/{client:external_id}', [ClientController::class, 'show'])->name('clients.show');
Route::get('/events/create', [EventController::class, 'create'])->name('events.create');
Route::post('/events', [EventController::class, 'store'])->name('events.store');
