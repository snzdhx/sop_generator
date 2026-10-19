<?php

use App\Http\Controllers\SopController;
use Illuminate\Support\Facades\Route;

Route::post('sops/preview', [SopController::class, 'preview']);
Route::apiResource('sops', SopController::class);
Route::get('sops/{sop}/download', [SopController::class, 'download']);
