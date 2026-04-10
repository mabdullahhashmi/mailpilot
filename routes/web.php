<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// API routes for warmup engine
Route::prefix('api/warmup')->group(function () {
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
    Route::post('campaigns/{id}/pause', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'pause']);
    Route::post('campaigns/{id}/resume', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'resume']);
    Route::post('campaigns/{id}/stop', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'stop']);
    Route::post('campaigns/{id}/restart', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'restart']);
    Route::get('campaigns/{id}/report', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'report']);

    // Event Logs
    Route::get('logs', [\App\Http\Controllers\Api\EventLogController::class, 'index']);
    Route::get('logs/performance', [\App\Http\Controllers\Api\EventLogController::class, 'performance']);
});
