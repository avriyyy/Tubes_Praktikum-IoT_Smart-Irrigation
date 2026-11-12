<?php

use App\Http\Controllers\ReadingController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'dashboard');

Route::get('/api/readings/latest', [ReadingController::class, 'latest']);
Route::get('/api/readings/history', [ReadingController::class, 'history']);
