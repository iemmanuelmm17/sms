<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Explicitly handle the preflight check for the broadcasting endpoint.
// (config/cors.php now covers broadcasting/* via HandleCors; this stays as a
// fallback and mirrors the request Origin instead of hardcoding localhost —
// the old header rejected every LAN origin.)
Route::options('/broadcasting/auth', function (\Illuminate\Http\Request $request) {
    return response('', 204)
        ->header('Access-Control-Allow-Origin', (string) $request->header('Origin', '*'))
        ->header('Vary', 'Origin')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With, Authorization, X-CSRF-TOKEN')
        ->header('Access-Control-Allow-Credentials', 'true');
});