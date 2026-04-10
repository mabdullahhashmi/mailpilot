<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardPageController;
use App\Http\Controllers\Auth\LoginController;

// Auth Routes (guest only)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Protected Dashboard Pages
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardPageController::class, 'index'])->name('dashboard');
    Route::get('/campaigns', [DashboardPageController::class, 'campaigns'])->name('dashboard.campaigns');
    Route::get('/profiles', [DashboardPageController::class, 'profiles'])->name('dashboard.profiles');
    Route::get('/senders', [DashboardPageController::class, 'senders'])->name('dashboard.senders');
    Route::get('/seeds', [DashboardPageController::class, 'seeds'])->name('dashboard.seeds');
    Route::get('/domains', [DashboardPageController::class, 'domains'])->name('dashboard.domains');
    Route::get('/logs', [DashboardPageController::class, 'logs'])->name('dashboard.logs');
    Route::get('/settings', [DashboardPageController::class, 'settings'])->name('dashboard.settings');
});

// Protected API routes for warmup engine
Route::prefix('api/warmup')->middleware(['auth', 'throttle:120,1'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [\App\Http\Controllers\Api\DashboardController::class, 'overview']);
    Route::get('/dashboard/readiness', [\App\Http\Controllers\Api\DashboardController::class, 'senderReadiness']);
    Route::get('/dashboard/activity-chart', [\App\Http\Controllers\Api\DashboardController::class, 'activityChart']);

    // Sender Mailboxes
    Route::apiResource('sender-mailboxes', \App\Http\Controllers\Api\SenderMailboxController::class);
    Route::post('sender-mailboxes/{id}/test-smtp', [\App\Http\Controllers\Api\SenderMailboxController::class, 'testSmtp']);
    Route::post('sender-mailboxes/{id}/test-imap', [\App\Http\Controllers\Api\SenderMailboxController::class, 'testImap']);
    Route::post('sender-mailboxes/{id}/pause', [\App\Http\Controllers\Api\SenderMailboxController::class, 'pause']);
    Route::post('sender-mailboxes/{id}/resume', [\App\Http\Controllers\Api\SenderMailboxController::class, 'resume']);

    // Seed Mailboxes
    Route::apiResource('seed-mailboxes', \App\Http\Controllers\Api\SeedMailboxController::class);
    Route::post('seed-mailboxes/{id}/pause', [\App\Http\Controllers\Api\SeedMailboxController::class, 'pause']);
    Route::post('seed-mailboxes/{id}/resume', [\App\Http\Controllers\Api\SeedMailboxController::class, 'resume']);

    // Domains
    Route::apiResource('domains', \App\Http\Controllers\Api\DomainController::class)->except(['update']);
    Route::post('domains/{id}/check-dns', [\App\Http\Controllers\Api\DomainController::class, 'checkDns']);

    // Warmup Profiles
    Route::apiResource('profiles', \App\Http\Controllers\Api\WarmupProfileController::class);

    // Warmup Campaigns
    Route::apiResource('campaigns', \App\Http\Controllers\Api\WarmupCampaignController::class)->only(['index', 'store', 'show']);
    Route::delete('campaigns/{id}', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'destroy']);
    Route::post('campaigns/{id}/start', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'startCampaign']);
    Route::post('campaigns/{id}/pause', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'pause']);
    Route::post('campaigns/{id}/resume', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'resume']);
    Route::post('campaigns/{id}/stop', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'stop']);
    Route::post('campaigns/{id}/restart', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'restart']);
    Route::get('campaigns/{id}/report', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'report']);

    // Settings
    Route::get('settings', [\App\Http\Controllers\Api\SettingsController::class, 'index']);
    Route::put('settings', [\App\Http\Controllers\Api\SettingsController::class, 'update']);
    Route::put('settings/profile', [\App\Http\Controllers\Api\SettingsController::class, 'updateProfile']);
    Route::put('settings/password', [\App\Http\Controllers\Api\SettingsController::class, 'updatePassword']);

    // Event Logs
    Route::get('event-logs', [\App\Http\Controllers\Api\EventLogController::class, 'index']);
    Route::get('event-logs/performance', [\App\Http\Controllers\Api\EventLogController::class, 'performance']);
});
