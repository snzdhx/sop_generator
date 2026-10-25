<?php

use App\Http\Controllers\SopController;
use Illuminate\Support\Facades\Route;

Route::apiResource('sops', SopController::class);
Route::get('sops/{sop}/download', [SopController::class, 'download']);
