<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (Request $request) => $request->user()
    ? redirect()->route('dashboard.redirect')
    : redirect()->route('login'))->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', fn (Request $request) => redirect()->route('dashboard', [
        'current_team' => $request->user()->ensureCurrentTeam()->slug,
    ]))->name('dashboard.redirect');

    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

require __DIR__.'/settings.php';
