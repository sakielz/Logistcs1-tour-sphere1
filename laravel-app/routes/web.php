<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

// Redirect root to login
Route::get('/', function () {
    return redirect()->route('login');
});

// Login page
Route::get('/login', function () {
    return view('auth.login');
})->name('login');

// Login form submission
Route::post('/login', [LoginController::class, 'store'])->name('login.submit');

// Logout
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
