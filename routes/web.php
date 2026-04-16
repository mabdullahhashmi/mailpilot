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
    Route::get('/templates', [DashboardPageController::class, 'templates'])->name('dashboard.templates');
    Route::get('/campaign-flow', [DashboardPageController::class, 'campaignFlow'])->name('dashboard.campaign-flow');
    Route::get('/campaigns/{id}', [DashboardPageController::class, 'campaignDetail'])->name('dashboard.campaign-detail');
    Route::get('/sender-health', [DashboardPageController::class, 'senderHealth'])->name('dashboard.sender-health');
    Route::get('/dns-health', [DashboardPageController::class, 'dnsHealth'])->name('dashboard.dns-health');
    Route::get('/system-health', [DashboardPageController::class, 'systemHealth'])->name('dashboard.system-health');
    Route::get('/progress-report', [DashboardPageController::class, 'progressReport'])->name('dashboard.progress-report');
    Route::get('/deliverability', [DashboardPageController::class, 'deliverability'])->name('dashboard.deliverability');
    Route::get('/diagnostics', [DashboardPageController::class, 'diagnostics'])->name('dashboard.diagnostics');
});

// Protected API routes for warmup engine
Route::prefix('api/warmup')->middleware(['auth', 'throttle:120,1'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [\App\Http\Controllers\Api\DashboardController::class, 'overview']);
    Route::get('/dashboard/readiness', [\App\Http\Controllers\Api\DashboardController::class, 'senderReadiness']);
    Route::get('/dashboard/activity-chart', [\App\Http\Controllers\Api\DashboardController::class, 'activityChart']);

    // Sender Mailboxes
    Route::post('sender-mailboxes/bulk', [\App\Http\Controllers\Api\SenderMailboxController::class, 'bulkStore']);
    Route::post('sender-mailboxes/test-smtp-credentials', [\App\Http\Controllers\Api\SenderMailboxController::class, 'testSmtpCredentials']);
    Route::apiResource('sender-mailboxes', \App\Http\Controllers\Api\SenderMailboxController::class);
    Route::post('sender-mailboxes/{id}/test-smtp', [\App\Http\Controllers\Api\SenderMailboxController::class, 'testSmtp']);
    Route::post('sender-mailboxes/{id}/test-imap', [\App\Http\Controllers\Api\SenderMailboxController::class, 'testImap']);
    Route::get('sender-mailboxes/{id}/inbox', [\App\Http\Controllers\Api\SenderMailboxController::class, 'inbox']);
    Route::post('sender-mailboxes/{id}/pause', [\App\Http\Controllers\Api\SenderMailboxController::class, 'pause']);
    Route::post('sender-mailboxes/{id}/resume', [\App\Http\Controllers\Api\SenderMailboxController::class, 'resume']);

    // Seed Mailboxes
    Route::get('seed-mailboxes/{id}/history', [\App\Http\Controllers\Api\SeedMailboxController::class, 'history']);
    Route::post('seed-mailboxes/bulk', [\App\Http\Controllers\Api\SeedMailboxController::class, 'bulkStore']);
    Route::post('seed-mailboxes/test-all-connections', [\App\Http\Controllers\Api\SeedMailboxController::class, 'testAllConnections']);
    Route::apiResource('seed-mailboxes', \App\Http\Controllers\Api\SeedMailboxController::class);
    Route::post('seed-mailboxes/{id}/test-smtp', [\App\Http\Controllers\Api\SeedMailboxController::class, 'testSmtp']);
    Route::post('seed-mailboxes/{id}/test-imap', [\App\Http\Controllers\Api\SeedMailboxController::class, 'testImap']);
    Route::get('seed-mailboxes/{id}/inbox', [\App\Http\Controllers\Api\SeedMailboxController::class, 'inbox']);
    Route::post('seed-mailboxes/{id}/pause', [\App\Http\Controllers\Api\SeedMailboxController::class, 'pause']);
    Route::post('seed-mailboxes/{id}/resume', [\App\Http\Controllers\Api\SeedMailboxController::class, 'resume']);

    // Domains
    Route::apiResource('domains', \App\Http\Controllers\Api\DomainController::class);
    Route::post('domains/{id}/check-dns', [\App\Http\Controllers\Api\DomainController::class, 'checkDns']);

    // Warmup Profiles
    Route::apiResource('profiles', \App\Http\Controllers\Api\WarmupProfileController::class);

    // Warmup Campaigns
    Route::post('campaigns/bulk', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'bulkStore']);
    Route::apiResource('campaigns', \App\Http\Controllers\Api\WarmupCampaignController::class)->only(['index', 'store', 'show']);
    Route::delete('campaigns/{id}', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'destroy']);
    Route::post('campaigns/{id}/start', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'startCampaign']);
    Route::post('campaigns/{id}/pause', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'pause']);
    Route::post('campaigns/{id}/resume', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'resume']);
    Route::post('campaigns/{id}/stop', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'stop']);
    Route::post('campaigns/{id}/restart', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'restart']);
    Route::get('campaigns/{id}/report', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'report']);
    Route::get('campaigns/{id}/schedule', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'schedule']);
    Route::get('campaigns/{id}/events', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'events']);
    Route::get('campaigns/{id}/seed-eligibility', [\App\Http\Controllers\Api\WarmupCampaignController::class, 'seedEligibility']);

    // Flow Test (isolated from daily warmup quota)
    Route::get('flow-tests/meta', [\App\Http\Controllers\Api\FlowTestController::class, 'meta']);
    Route::get('flow-tests', [\App\Http\Controllers\Api\FlowTestController::class, 'index']);
    Route::get('flow-tests/{id}', [\App\Http\Controllers\Api\FlowTestController::class, 'show']);
    Route::post('flow-tests/start', [\App\Http\Controllers\Api\FlowTestController::class, 'start']);

    // Settings
    Route::get('settings', [\App\Http\Controllers\Api\SettingsController::class, 'index']);
    Route::put('settings', [\App\Http\Controllers\Api\SettingsController::class, 'update']);
    Route::put('settings/profile', [\App\Http\Controllers\Api\SettingsController::class, 'updateProfile']);
    Route::put('settings/password', [\App\Http\Controllers\Api\SettingsController::class, 'updatePassword']);

    // Event Logs
    Route::get('event-logs', [\App\Http\Controllers\Api\EventLogController::class, 'index']);
    Route::get('event-logs/performance', [\App\Http\Controllers\Api\EventLogController::class, 'performance']);

    // Content Templates
    Route::apiResource('content-templates', \App\Http\Controllers\Api\ContentTemplateController::class);

    // Alerts
    Route::get('alerts', [\App\Http\Controllers\Api\AlertController::class, 'index']);
    Route::get('alerts/unread-count', [\App\Http\Controllers\Api\AlertController::class, 'unreadCount']);
    Route::post('alerts/mark-all-read', [\App\Http\Controllers\Api\AlertController::class, 'markAllRead']);
    Route::post('alerts/{id}/read', [\App\Http\Controllers\Api\AlertController::class, 'markRead']);
    Route::post('alerts/{id}/dismiss', [\App\Http\Controllers\Api\AlertController::class, 'dismiss']);

    // CSV Import
    Route::post('import/senders', [\App\Http\Controllers\Api\ImportController::class, 'importSenders']);
    Route::post('import/seeds', [\App\Http\Controllers\Api\ImportController::class, 'importSeeds']);

    // Sender Health (F1)
    Route::get('sender-health', [\App\Http\Controllers\Api\SenderHealthController::class, 'index']);
    Route::get('sender-health/{id}', [\App\Http\Controllers\Api\SenderHealthController::class, 'show']);

    // DNS Health (F2)
    Route::get('dns-health', [\App\Http\Controllers\Api\DnsHealthController::class, 'index']);
    Route::post('dns-health/{id}/check', [\App\Http\Controllers\Api\DnsHealthController::class, 'check']);
    Route::post('dns-health/check-all', [\App\Http\Controllers\Api\DnsHealthController::class, 'checkAll']);

    // Blacklist Monitor (F3)
    Route::post('blacklist/check', [\App\Http\Controllers\Api\BlacklistController::class, 'check']);
    Route::post('blacklist/check-all', [\App\Http\Controllers\Api\BlacklistController::class, 'checkAll']);

    // System Health (F4)
    Route::get('system-health', [\App\Http\Controllers\Api\SystemHealthController::class, 'index']);
    Route::post('system-health/trigger-planner', [\App\Http\Controllers\Api\SystemHealthController::class, 'triggerPlanner']);
    Route::post('system-health/trigger-scheduler', [\App\Http\Controllers\Api\SystemHealthController::class, 'triggerScheduler']);
    Route::get('system-health/readiness', [\App\Http\Controllers\Api\SystemHealthController::class, 'readinessCheck']);

    // Data Export (F5)
    Route::get('export/event-logs', [\App\Http\Controllers\Api\ExportController::class, 'eventLogs']);
    Route::get('export/sender-health', [\App\Http\Controllers\Api\ExportController::class, 'senderHealth']);

    // Diagnostics (Phase H)
    Route::get('diagnostics/live', [\App\Http\Controllers\Api\DiagnosticController::class, 'liveDiagnostic']);
    Route::get('diagnostics/cron-health', [\App\Http\Controllers\Api\DiagnosticController::class, 'cronHealth']);
    Route::post('diagnostics/fix-stuck', [\App\Http\Controllers\Api\DiagnosticController::class, 'fixStuckEvents']);
    Route::get('diagnostics/slots/{campaignId}', [\App\Http\Controllers\Api\DiagnosticController::class, 'todaySlots']);
    Route::get('diagnostics/slots/{campaignId}/stats', [\App\Http\Controllers\Api\DiagnosticController::class, 'slotStats']);
    Route::get('diagnostics/seed-health', [\App\Http\Controllers\Api\DiagnosticController::class, 'seedHealthReport']);
    Route::post('diagnostics/seed-health/check', [\App\Http\Controllers\Api\DiagnosticController::class, 'runSeedHealthCheck']);
    Route::post('diagnostics/seed-health/{id}/re-enable', [\App\Http\Controllers\Api\DiagnosticController::class, 'reEnableSeed']);
    Route::get('diagnostics/content-warnings/{senderId}', [\App\Http\Controllers\Api\DiagnosticController::class, 'contentWarnings']);
    Route::get('diagnostics/history', [\App\Http\Controllers\Api\DiagnosticController::class, 'snapshotHistory']);

    // Deliverability Intelligence (Phase I)
    Route::get('deliverability/overview', [\App\Http\Controllers\Api\DeliverabilityController::class, 'overview']);
    Route::post('deliverability/placement/test', [\App\Http\Controllers\Api\DeliverabilityController::class, 'runPlacementTest']);
    Route::get('deliverability/placement/{senderId}/history', [\App\Http\Controllers\Api\DeliverabilityController::class, 'placementHistory']);
    Route::get('deliverability/placement/test/{testId}', [\App\Http\Controllers\Api\DeliverabilityController::class, 'placementTestDetail']);
    Route::get('deliverability/bounces', [\App\Http\Controllers\Api\DeliverabilityController::class, 'bounceOverview']);
    Route::get('deliverability/bounces/{senderId}', [\App\Http\Controllers\Api\DeliverabilityController::class, 'senderBounces']);
    Route::get('deliverability/bounces/suppression-candidates', [\App\Http\Controllers\Api\DeliverabilityController::class, 'suppressionCandidates']);
    Route::post('deliverability/bounces/suppress', [\App\Http\Controllers\Api\DeliverabilityController::class, 'suppressEmail']);
    Route::get('deliverability/bounce-log', [\App\Http\Controllers\Api\DeliverabilityController::class, 'bounceLog']);
    Route::get('deliverability/reputation', [\App\Http\Controllers\Api\DeliverabilityController::class, 'reputationOverview']);
    Route::get('deliverability/reputation/domain/{domainId}', [\App\Http\Controllers\Api\DeliverabilityController::class, 'domainReputation']);
    Route::get('deliverability/reputation/sender/{senderId}', [\App\Http\Controllers\Api\DeliverabilityController::class, 'senderReputation']);
    Route::post('deliverability/reputation/scan', [\App\Http\Controllers\Api\DeliverabilityController::class, 'runReputationScan']);
    Route::get('deliverability/strategy', [\App\Http\Controllers\Api\DeliverabilityController::class, 'strategyOverview']);
    Route::get('deliverability/strategy/{senderId}/analyze', [\App\Http\Controllers\Api\DeliverabilityController::class, 'analyzeSender']);
    Route::post('deliverability/strategy/{senderId}/apply', [\App\Http\Controllers\Api\DeliverabilityController::class, 'applyStrategy']);
    Route::get('deliverability/strategy/{senderId}/history', [\App\Http\Controllers\Api\DeliverabilityController::class, 'senderStrategyHistory']);
    Route::post('deliverability/strategy/run', [\App\Http\Controllers\Api\DeliverabilityController::class, 'runStrategyAnalysis']);
});
