<?php

use Illuminate\Support\Facades\Route;

// Backend hanya menyediakan API (lihat routes/api.php). Halaman ini sekadar cek server hidup.
Route::get('/', fn () => response()->json(['app' => 'SOP Generator API', 'status' => 'ok']));
