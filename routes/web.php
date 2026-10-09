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

// Travels & Tours - Logistics 1: Account Security & 2FA Routes
Route::middleware(['auth'])->prefix('system')->name('system.')->group(function () {
    Route::get('/account/security', [\App\Http\Controllers\AccountSecurityController::class, 'index'])->name('account.security');
    Route::get('/profile', [\App\Http\Controllers\AccountSecurityController::class, 'index'])->name('profile');
    Route::post('/account/security/provision', [\App\Http\Controllers\AccountSecurityController::class, 'provision'])->name('account.security.provision');
    Route::post('/account/security/confirm', [\App\Http\Controllers\AccountSecurityController::class, 'confirm'])->name('account.security.confirm');
    Route::post('/account/security/disable', [\App\Http\Controllers\AccountSecurityController::class, 'disable'])->name('account.security.disable');
    Route::get('/account/security/download-codes', [\App\Http\Controllers\AccountSecurityController::class, 'downloadRecoveryCodes'])->name('account.security.download-codes');
});

