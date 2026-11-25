<?php

use App\Http\Controllers\HistoryController;
use App\Http\Controllers\ReadingController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'dashboard');

Route::get('/riwayat', [HistoryController::class, 'index'])->name('riwayat.index');
Route::get('/riwayat/export', [HistoryController::class, 'export'])->name('riwayat.export');

Route::get('/api/readings/latest', [ReadingController::class, 'latest']);
Route::get('/api/readings/history', [ReadingController::class, 'history']);
