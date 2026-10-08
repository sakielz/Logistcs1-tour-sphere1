<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Travels & Tours - Logistics 1: Account Security & 2FA Routes
Route::middleware(['auth'])->prefix('system')->name('system.')->group(function () {
    Route::get('/account/security', [\App\Http\Controllers\AccountSecurityController::class, 'index'])->name('account.security');
    Route::get('/profile', [\App\Http\Controllers\AccountSecurityController::class, 'index'])->name('profile');
    Route::post('/account/security/provision', [\App\Http\Controllers\AccountSecurityController::class, 'provision'])->name('account.security.provision');
    Route::post('/account/security/confirm', [\App\Http\Controllers\AccountSecurityController::class, 'confirm'])->name('account.security.confirm');
    Route::post('/account/security/disable', [\App\Http\Controllers\AccountSecurityController::class, 'disable'])->name('account.security.disable');
    Route::get('/account/security/download-codes', [\App\Http\Controllers\AccountSecurityController::class, 'downloadRecoveryCodes'])->name('account.security.download-codes');
});

