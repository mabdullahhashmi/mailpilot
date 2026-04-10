@extends('layouts.app')

@section('title', 'Diagnostics')
@section('page-title', 'System Diagnostics')
@section('page-description', 'Real-time system health, cron watchdog, seed quality & slot schedule')

@section('content')
<div x-data="diagnosticsPage()" x-init="init()" class="space-y-6 p-6 fade-in">

    <!-- Quick Actions Bar -->
    <div class="flex flex-wrap items-center gap-3">
        <button @click="runDiagnostic()" :disabled="loading" class="btn-primary px-4 py-2 rounded-xl text-sm font-medium text-white flex items-center gap-2">
            <i data-lucide="scan-line" class="w-4 h-4"></i>
            <span x-text="loading ? 'Scanning...' : 'Run Diagnostic'"></span>
        </button>
        <button @click="fixStuck()" class="px-4 py-2 rounded-xl text-sm font-medium text-amber-400 border border-amber-500/30 hover:bg-amber-500/10 transition flex items-center gap-2">
            <i data-lucide="wrench" class="w-4 h-4"></i>
            Fix Stuck Events
        </button>
        <button @click="runSeedCheck()" class="px-4 py-2 rounded-xl text-sm font-medium text-cyan-400 border border-cyan-500/30 hover:bg-cyan-500/10 transition flex items-center gap-2">
            <i data-lucide="heart-pulse" class="w-4 h-4"></i>
            Check Seed Health
        </button>
        <div class="ml-auto text-xs text-zinc-500" x-show="lastRun" x-text="'Last scan: ' + lastRun"></div>
    </div>

    <!-- Overall Status Banner -->
    <div x-show="snapshot" class="glass rounded-2xl p-5" :class="{
        'border-l-4 border-emerald-500': snapshot?.overall_status === 'healthy',
        'border-l-4 border-amber-500': snapshot?.overall_status === 'degraded',
        'border-l-4 border-red-500': snapshot?.overall_status === 'critical'
    }">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center" :class="{
                'gradient-success': snapshot?.overall_status === 'healthy',
                'gradient-warning': snapshot?.overall_status === 'degraded',
                'gradient-danger': snapshot?.overall_status === 'critical'
            }">
                <i :data-lucide="snapshot?.overall_status === 'healthy' ? 'shield-check' : snapshot?.overall_status === 'degraded' ? 'alert-triangle' : 'shield-alert'" class="w-6 h-6 text-white"></i>
            </div>
            <div>
                <h3 class="text-white font-semibold text-lg capitalize" x-text="'System Status: ' + (snapshot?.overall_status || 'Unknown')"></h3>
                <p class="text-zinc-400 text-sm" x-text="'Snapshot: ' + (snapshot?.snapshot_date || 'N/A')"></p>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-4" x-show="snapshot">
        <template x-for="stat in statsCards" :key="stat.label">
            <div class="glass rounded-xl p-4 stat-card">
                <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium" x-text="stat.label"></p>
                <p class="text-white text-2xl font-bold mt-1" x-text="stat.value"></p>
                <div class="flex items-center gap-1 mt-1">
                    <div class="w-1.5 h-1.5 rounded-full" :class="stat.color"></div>
                    <span class="text-[11px]" :class="stat.textColor" x-text="stat.sub"></span>
                </div>
            </div>
        </template>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 bg-surface-900/50 p-1 rounded-xl w-fit">
        <template x-for="tab in ['cron', 'seeds', 'slots', 'history']" :key="tab">
            <button @click="activeTab = tab" class="px-4 py-2 rounded-lg text-sm font-medium transition"
                    :class="activeTab === tab ? 'bg-brand-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-white/5'"
                    x-text="tab === 'cron' ? 'Cron Watchdog' : tab === 'seeds' ? 'Seed Health' : tab === 'slots' ? 'Send Slots' : 'History'">
            </button>
        </template>
    </div>

    <!-- Cron Watchdog Tab -->
    <div x-show="activeTab === 'cron'" class="glass rounded-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-white/5">
            <h3 class="text-white font-semibold">Cron Task Monitor</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider text-zinc-500 border-b border-white/5">
                        <th class="px-5 py-3">Task</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Last Run</th>
                        <th class="px-5 py-3">Last Success</th>
                        <th class="px-5 py-3">Failures</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <template x-for="cron in cronHealth" :key="cron.task">
                        <tr class="table-row">
                            <td class="px-5 py-3">
                                <span class="text-sm font-medium text-white" x-text="cron.task"></span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="badge px-2 py-0.5 rounded-full" :class="{
                                    'bg-emerald-500/15 text-emerald-400': cron.status === 'healthy',
                                    'bg-amber-500/15 text-amber-400': cron.status === 'late',
                                    'bg-red-500/15 text-red-400': cron.status === 'missed' || cron.status === 'failed'
                                }" x-text="cron.status"></span>
                            </td>
                            <td class="px-5 py-3 text-sm text-zinc-400" x-text="cron.last_run || 'Never'"></td>
                            <td class="px-5 py-3 text-sm text-zinc-400" x-text="cron.last_success || 'Never'"></td>
                            <td class="px-5 py-3">
                                <span class="text-sm" :class="cron.failures > 0 ? 'text-red-400 font-semibold' : 'text-zinc-500'" x-text="cron.failures"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div x-show="cronHealth.length === 0" class="px-5 py-8 text-center text-zinc-500 text-sm">
            No cron heartbeats recorded yet. Tasks will appear after their first run.
        </div>
    </div>

    <!-- Seed Health Tab -->
    <div x-show="activeTab === 'seeds'" class="glass rounded-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-white/5 flex items-center justify-between">
            <h3 class="text-white font-semibold">Seed Mailbox Health</h3>
            <span class="text-xs text-zinc-500" x-text="seedReport.length + ' seeds'"></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider text-zinc-500 border-b border-white/5">
                        <th class="px-5 py-3">Email</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Health</th>
                        <th class="px-5 py-3">Interactions</th>
                        <th class="px-5 py-3">Failures</th>
                        <th class="px-5 py-3">Replies</th>
                        <th class="px-5 py-3">Last Used</th>
                        <th class="px-5 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <template x-for="seed in seedReport" :key="seed.id">
                        <tr class="table-row">
                            <td class="px-5 py-3 text-sm text-white font-medium" x-text="seed.email"></td>
                            <td class="px-5 py-3">
                                <span class="badge px-2 py-0.5 rounded-full" :class="{
                                    'bg-emerald-500/15 text-emerald-400': seed.status === 'active',
                                    'bg-red-500/15 text-red-400': seed.status === 'disabled',
                                    'bg-amber-500/15 text-amber-400': seed.status === 'paused'
                                }" x-text="seed.status"></span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-16 h-1.5 bg-surface-800 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full transition-all" :class="{
                                            'bg-emerald-500': seed.health_score >= 70,
                                            'bg-amber-500': seed.health_score >= 40 && seed.health_score < 70,
                                            'bg-red-500': seed.health_score < 40
                                        }" :style="'width:' + seed.health_score + '%'"></div>
                                    </div>
                                    <span class="text-sm font-semibold" :class="{
                                        'text-emerald-400': seed.health_score >= 70,
                                        'text-amber-400': seed.health_score >= 40 && seed.health_score < 70,
                                        'text-red-400': seed.health_score < 40
                                    }" x-text="seed.health_score"></span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-sm text-zinc-400" x-text="seed.total_interactions"></td>
                            <td class="px-5 py-3">
                                <span class="text-sm" :class="seed.failed_interactions > 0 ? 'text-red-400' : 'text-zinc-500'" x-text="seed.failed_interactions"></span>
                            </td>
                            <td class="px-5 py-3 text-sm text-zinc-400" x-text="seed.total_replies"></td>
                            <td class="px-5 py-3 text-sm text-zinc-500" x-text="seed.last_used || 'Never'"></td>
                            <td class="px-5 py-3">
                                <button x-show="seed.status === 'disabled'" @click="reEnableSeed(seed.id)" class="text-xs text-brand-400 hover:text-brand-300 font-medium">
                                    Re-enable
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div x-show="seedReport.length === 0" class="px-5 py-8 text-center text-zinc-500 text-sm">
            No seed data available. Run a health check first.
        </div>
    </div>

    <!-- Send Slots Tab -->
    <div x-show="activeTab === 'slots'" class="glass rounded-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-white/5 flex items-center justify-between">
            <h3 class="text-white font-semibold">Today's Send Schedule</h3>
            <div class="flex items-center gap-3">
                <select x-model="selectedCampaign" @change="loadSlots()" class="input-dark rounded-lg px-3 py-1.5 text-sm text-white">
                    <option value="">Select Campaign</option>
                    <template x-for="c in campaigns" :key="c.id">
                        <option :value="c.id" x-text="'Campaign #' + c.id"></option>
                    </template>
                </select>
            </div>
        </div>

        <!-- Slot Stats -->
        <div x-show="slotStats" class="grid grid-cols-4 gap-4 px-5 py-4 border-b border-white/5">
            <div class="text-center">
                <p class="text-2xl font-bold text-white" x-text="slotStats?.total || 0"></p>
                <p class="text-[11px] text-zinc-500 uppercase">Total</p>
            </div>
            <div class="text-center">
                <p class="text-2xl font-bold text-emerald-400" x-text="slotStats?.completed || 0"></p>
                <p class="text-[11px] text-zinc-500 uppercase">Completed</p>
            </div>
            <div class="text-center">
                <p class="text-2xl font-bold text-amber-400" x-text="slotStats?.planned || 0"></p>
                <p class="text-[11px] text-zinc-500 uppercase">Pending</p>
            </div>
            <div class="text-center">
                <p class="text-2xl font-bold text-red-400" x-text="(slotStats?.failed || 0) + (slotStats?.skipped || 0)"></p>
                <p class="text-[11px] text-zinc-500 uppercase">Skipped/Failed</p>
            </div>
        </div>

        <!-- Slot Timeline -->
        <div class="divide-y divide-white/5">
            <template x-for="slot in todaySlots" :key="slot.id">
                <div class="px-5 py-3 flex items-center gap-4 table-row">
                    <div class="w-16 text-center">
                        <span class="text-sm font-mono text-white" x-text="slot.planned_at"></span>
                    </div>
                    <div class="flex-shrink-0">
                        <span class="badge px-2 py-0.5 rounded-full" :class="{
                            'bg-emerald-500/15 text-emerald-400': slot.status === 'completed',
                            'bg-blue-500/15 text-blue-400': slot.status === 'planned',
                            'bg-amber-500/15 text-amber-400': slot.status === 'executing',
                            'bg-zinc-500/15 text-zinc-400': slot.status === 'skipped',
                            'bg-red-500/15 text-red-400': slot.status === 'failed'
                        }" x-text="slot.status"></span>
                    </div>
                    <div class="flex-shrink-0">
                        <span class="badge px-2 py-0.5 rounded-full bg-white/5 text-zinc-300" x-text="slot.type.replace('_', ' ')"></span>
                    </div>
                    <div class="flex-1 min-w-0 text-sm text-zinc-400 truncate">
                        <span x-text="slot.sender"></span> → <span x-text="slot.seed || '—'"></span>
                    </div>
                    <div x-show="slot.executed_at" class="text-xs text-zinc-500">
                        <span x-text="'Done: ' + slot.executed_at"></span>
                    </div>
                    <div x-show="slot.skip_reason" class="text-xs text-amber-400" x-text="slot.skip_reason"></div>
                </div>
            </template>
        </div>
        <div x-show="!selectedCampaign" class="px-5 py-8 text-center text-zinc-500 text-sm">
            Select a campaign to view today's send schedule.
        </div>
        <div x-show="selectedCampaign && todaySlots.length === 0" class="px-5 py-8 text-center text-zinc-500 text-sm">
            No slots scheduled for today.
        </div>
    </div>

    <!-- Snapshot History Tab -->
    <div x-show="activeTab === 'history'" class="glass rounded-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-white/5">
            <h3 class="text-white font-semibold">Diagnostic History (Last 30 Days)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider text-zinc-500 border-b border-white/5">
                        <th class="px-5 py-3">Date</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Senders</th>
                        <th class="px-5 py-3">Seeds</th>
                        <th class="px-5 py-3">Events</th>
                        <th class="px-5 py-3">Stuck</th>
                        <th class="px-5 py-3">Avg Health</th>
                        <th class="px-5 py-3">Bounce Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <template x-for="s in snapshots" :key="s.id">
                        <tr class="table-row">
                            <td class="px-5 py-3 text-sm text-white font-medium" x-text="s.snapshot_date"></td>
                            <td class="px-5 py-3">
                                <span class="badge px-2 py-0.5 rounded-full" :class="{
                                    'bg-emerald-500/15 text-emerald-400': s.overall_status === 'healthy',
                                    'bg-amber-500/15 text-amber-400': s.overall_status === 'degraded',
                                    'bg-red-500/15 text-red-400': s.overall_status === 'critical'
                                }" x-text="s.overall_status"></span>
                            </td>
                            <td class="px-5 py-3 text-sm text-zinc-400" x-text="s.active_senders + '/' + s.total_senders"></td>
                            <td class="px-5 py-3 text-sm text-zinc-400" x-text="s.active_seeds + '/' + s.total_seeds"></td>
                            <td class="px-5 py-3 text-sm text-zinc-400" x-text="s.events_completed + '/' + s.events_planned"></td>
                            <td class="px-5 py-3">
                                <span class="text-sm" :class="s.events_stuck > 0 ? 'text-red-400 font-semibold' : 'text-zinc-500'" x-text="s.events_stuck"></span>
                            </td>
                            <td class="px-5 py-3 text-sm" :class="s.avg_health_score >= 70 ? 'text-emerald-400' : s.avg_health_score >= 40 ? 'text-amber-400' : 'text-red-400'" x-text="parseFloat(s.avg_health_score).toFixed(1)"></td>
                            <td class="px-5 py-3 text-sm" :class="parseFloat(s.avg_bounce_rate) > 5 ? 'text-red-400' : parseFloat(s.avg_bounce_rate) > 2 ? 'text-amber-400' : 'text-emerald-400'" x-text="parseFloat(s.avg_bounce_rate).toFixed(1) + '%'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div x-show="snapshots.length === 0" class="px-5 py-8 text-center text-zinc-500 text-sm">
            No diagnostic snapshots yet. Run a diagnostic scan to create one.
        </div>
    </div>

    <!-- Toast -->
    <div x-show="toast" x-cloak x-transition class="fixed bottom-6 right-6 z-50 px-4 py-3 rounded-xl shadow-2xl text-sm font-medium" :class="{
        'bg-emerald-500/90 text-white': toastType === 'success',
        'bg-red-500/90 text-white': toastType === 'error',
        'bg-amber-500/90 text-white': toastType === 'warning'
    }" x-text="toast"></div>
</div>

<script>
function diagnosticsPage() {
    return {
        loading: false,
        activeTab: 'cron',
        snapshot: null,
        cronHealth: [],
        seedReport: [],
        todaySlots: [],
        slotStats: null,
        snapshots: [],
        campaigns: [],
        selectedCampaign: '',
        lastRun: null,
        toast: null,
        toastType: 'success',

        get statsCards() {
            if (!this.snapshot) return [];
            const s = this.snapshot;
            return [
                { label: 'Active Senders', value: s.active_senders, sub: `of ${s.total_senders}`, color: 'bg-emerald-400', textColor: 'text-zinc-400' },
                { label: 'Active Seeds', value: s.active_seeds, sub: `${s.disabled_seeds} disabled`, color: s.disabled_seeds > 0 ? 'bg-amber-400' : 'bg-emerald-400', textColor: s.disabled_seeds > 0 ? 'text-amber-400' : 'text-zinc-400' },
                { label: 'Events Today', value: s.events_completed, sub: `of ${s.events_planned} planned`, color: 'bg-blue-400', textColor: 'text-zinc-400' },
                { label: 'Stuck Events', value: s.events_stuck, sub: s.events_stuck > 0 ? 'needs attention' : 'all clear', color: s.events_stuck > 0 ? 'bg-red-400' : 'bg-emerald-400', textColor: s.events_stuck > 0 ? 'text-red-400' : 'text-zinc-400' },
                { label: 'Queue Lag', value: s.avg_queue_lag_seconds + 's', sub: s.avg_queue_lag_seconds > 60 ? 'high lag' : 'normal', color: s.avg_queue_lag_seconds > 60 ? 'bg-amber-400' : 'bg-emerald-400', textColor: s.avg_queue_lag_seconds > 60 ? 'text-amber-400' : 'text-zinc-400' },
                { label: 'Health Score', value: parseFloat(s.avg_health_score).toFixed(0), sub: `${parseFloat(s.avg_bounce_rate).toFixed(1)}% bounces`, color: parseFloat(s.avg_health_score) >= 70 ? 'bg-emerald-400' : 'bg-amber-400', textColor: parseFloat(s.avg_bounce_rate) > 2 ? 'text-amber-400' : 'text-zinc-400' },
            ];
        },

        async init() {
            await this.loadCampaigns();
            await this.loadSnapshots();
        },

        async loadCampaigns() {
            try {
                const r = await fetch('/api/warmup/campaigns');
                if (r.ok) { const d = await r.json(); this.campaigns = d.data || d; }
            } catch {}
        },

        async runDiagnostic() {
            this.loading = true;
            try {
                const r = await fetch('/api/warmup/diagnostics/live');
                const d = await r.json();
                this.snapshot = d.snapshot;
                this.cronHealth = d.cron_health;
                this.lastRun = d.generated_at;
                this.showToast('Diagnostic scan complete', 'success');
            } catch (e) { this.showToast('Scan failed: ' + e.message, 'error'); }
            this.loading = false;
        },

        async fixStuck() {
            try {
                const r = await fetch('/api/warmup/diagnostics/fix-stuck', { method: 'POST', headers: {'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content} });
                const d = await r.json();
                this.showToast(d.message, 'success');
            } catch (e) { this.showToast('Fix failed: ' + e.message, 'error'); }
        },

        async runSeedCheck() {
            try {
                const r = await fetch('/api/warmup/diagnostics/seed-health/check', { method: 'POST', headers: {'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content} });
                const d = await r.json();
                this.showToast(`Checked ${d.checked} seeds, ${d.disabled} disabled, ${d.warnings} warnings`, d.disabled > 0 ? 'warning' : 'success');
                await this.loadSeedReport();
            } catch (e) { this.showToast('Check failed: ' + e.message, 'error'); }
        },

        async loadSeedReport() {
            try {
                const r = await fetch('/api/warmup/diagnostics/seed-health');
                if (r.ok) this.seedReport = await r.json();
            } catch {}
        },

        async reEnableSeed(id) {
            try {
                const r = await fetch(`/api/warmup/diagnostics/seed-health/${id}/re-enable`, { method: 'POST', headers: {'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content} });
                const d = await r.json();
                this.showToast(d.message, 'success');
                await this.loadSeedReport();
            } catch (e) { this.showToast('Re-enable failed', 'error'); }
        },

        async loadSlots() {
            if (!this.selectedCampaign) return;
            try {
                const [slotsRes, statsRes] = await Promise.all([
                    fetch(`/api/warmup/diagnostics/slots/${this.selectedCampaign}`),
                    fetch(`/api/warmup/diagnostics/slots/${this.selectedCampaign}/stats`)
                ]);
                if (slotsRes.ok) this.todaySlots = await slotsRes.json();
                if (statsRes.ok) this.slotStats = await statsRes.json();
            } catch {}
        },

        async loadSnapshots() {
            try {
                const r = await fetch('/api/warmup/diagnostics/history');
                if (r.ok) this.snapshots = await r.json();
            } catch {}
        },

        showToast(msg, type = 'success') {
            this.toast = msg;
            this.toastType = type;
            setTimeout(() => this.toast = null, 4000);
        }
    }
}
</script>

<script>
document.addEventListener('DOMContentLoaded', () => lucide.createIcons());
document.addEventListener('alpine:initialized', () => setTimeout(() => lucide.createIcons(), 100));
</script>
@endsection
