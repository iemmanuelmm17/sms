<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Explicitly handle the preflight check for the broadcasting endpoint
Route::options('/broadcasting/auth', function () {
    return response('', 204)
        ->header('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With, Authorization, X-CSRF-TOKEN')
        ->header('Access-Control-Allow-Credentials', 'true');
});