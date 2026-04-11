@extends('layouts.app')
@section('title', 'System Health')
@section('page-title', 'System Health')
@section('page-description', 'Queue status, cron monitoring, and system diagnostics')

@section('content')
<div x-data="systemHealthPage()" x-init="init()">

    <!-- Status Banner -->
    <div class="glass rounded-2xl p-5 mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center" :class="overallOk ? 'gradient-success' : 'gradient-danger'">
                    <i :data-lucide="overallOk ? 'check-circle' : 'alert-circle'" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h3 class="text-white font-semibold text-lg" x-text="overallOk ? 'All Systems Operational' : 'Issues Detected'"></h3>
                    <p class="text-zinc-500 text-xs mt-0.5">Last refreshed: <span x-text="lastRefresh"></span></p>
                </div>
            </div>
            <button @click="load()" :disabled="loading" class="btn-primary px-4 py-2.5 rounded-xl text-sm text-white font-medium flex items-center gap-2">
                <span :class="loading && 'animate-spin'" class="inline-flex"><i data-lucide="refresh-cw" class="w-4 h-4"></i></span> Refresh
            </button>
        </div>
    </div>

    <!-- Readiness Check + Manual Controls -->
    <div class="glass rounded-2xl p-5 mb-6">
        <div class="flex items-center gap-2 mb-4">
            <i data-lucide="shield-check" class="w-4 h-4 text-brand-400"></i>
            <h3 class="text-white font-semibold text-sm">Warmup Engine Controls</h3>
        </div>

        <!-- Readiness Checks -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2 mb-5">
            <template x-for="[key, label, icon] in [['senders','Senders','mail'],['seeds','Seeds','inbox'],['templates','Templates','file-text'],['campaigns','Campaigns','flame'],['events','Events','zap'],['domains','Domains','globe']]" :key="key">
                <div class="p-3 rounded-xl bg-white/[0.03] text-center">
                    <i :data-lucide="icon" class="w-4 h-4 mx-auto mb-1.5" :class="readiness.checks?.[key]?.ok ? 'text-emerald-400' : 'text-red-400'"></i>
                    <p class="text-[10px] font-semibold uppercase tracking-wider" :class="readiness.checks?.[key]?.ok ? 'text-emerald-400' : 'text-red-400'" x-text="label"></p>
                    <p class="text-[9px] text-zinc-600 mt-0.5 leading-tight" x-text="readiness.checks?.[key]?.detail ?? 'Checking...'"></p>
                </div>
            </template>
        </div>

        <!-- Manual Trigger Buttons -->
        <div class="flex flex-wrap items-center gap-3 pt-3 border-t border-white/5">
            <button @click="runPlanner()" :disabled="runningPlanner" class="btn-primary px-4 py-2.5 rounded-xl text-xs text-white font-medium flex items-center gap-2">
                <span :class="runningPlanner && 'animate-spin'" class="inline-flex"><i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i></span>
                <span x-text="runningPlanner ? 'Planning...' : 'Run Daily Planner'"></span>
            </button>
            <button @click="runScheduler()" :disabled="runningScheduler" class="px-4 py-2.5 rounded-xl text-xs font-medium flex items-center gap-2 bg-emerald-500/15 text-emerald-400 hover:bg-emerald-500/25 transition">
                <span :class="runningScheduler && 'animate-spin'" class="inline-flex"><i data-lucide="play" class="w-3.5 h-3.5"></i></span>
                <span x-text="runningScheduler ? 'Processing...' : 'Process Due Events'"></span>
            </button>
            <span class="text-zinc-600 text-[10px] leading-tight max-w-xs">Planner creates threads & events for active campaigns. Scheduler executes due events (sends emails).</span>
        </div>
    </div>

    <!-- Core Metrics -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-brand-500/15 flex items-center justify-center">
                    <i data-lucide="layers" class="w-5 h-5 text-brand-400"></i>
                </div>
                <span class="badge px-2 py-0.5 rounded-full" :class="data.queue?.pending_jobs > 10 ? 'bg-amber-500/15 text-amber-400' : 'bg-emerald-500/15 text-emerald-400'" x-text="data.queue?.pending_jobs > 10 ? 'busy' : 'ok'"></span>
            </div>
            <p class="text-2xl font-bold text-white" x-text="data.queue?.pending_jobs ?? '—'"></p>
            <p class="text-zinc-500 text-xs mt-1 uppercase tracking-wider">Queue Pending</p>
        </div>

        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl" :class="(data.queue?.failed_jobs || 0) > 0 ? 'bg-red-500/15' : 'bg-emerald-500/15'" style="display:flex;align-items:center;justify-content:center;">
                    <i data-lucide="x-circle" class="w-5 h-5" :class="(data.queue?.failed_jobs || 0) > 0 ? 'text-red-400' : 'text-emerald-400'"></i>
                </div>
            </div>
            <p class="text-2xl font-bold" :class="(data.queue?.failed_jobs || 0) > 0 ? 'text-red-400' : 'text-white'" x-text="data.queue?.failed_jobs ?? '—'"></p>
            <p class="text-zinc-500 text-xs mt-1 uppercase tracking-wider">Failed Jobs</p>
        </div>

        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-amber-500/15 flex items-center justify-center">
                    <i data-lucide="pause-circle" class="w-5 h-5 text-amber-400"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-amber-400" x-text="data.auto_pause_count ?? 0"></p>
            <p class="text-zinc-500 text-xs mt-1 uppercase tracking-wider">Auto-Paused</p>
        </div>

        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl bg-blue-500/15 flex items-center justify-center">
                    <i data-lucide="zap" class="w-5 h-5 text-blue-400"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-white" x-text="data.pending_events ?? 0"></p>
            <p class="text-zinc-500 text-xs mt-1 uppercase tracking-wider">Pending Events</p>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        <!-- Cron Status -->
        <div class="glass rounded-2xl p-5">
            <div class="flex items-center gap-2 mb-4">
                <i data-lucide="clock" class="w-4 h-4 text-brand-400"></i>
                <h3 class="text-white font-semibold text-sm">Cron Timestamps</h3>
            </div>
            <div class="space-y-3">
                <template x-for="[label, key] in [['Scheduler', 'last_scheduler'], ['Planner', 'last_planner'], ['Health', 'last_health'], ['DNS Check', 'last_dns_check']]" :key="key">
                    <div class="flex items-center justify-between p-3 rounded-xl bg-white/[0.03]">
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full" :class="isCronRecent(data.cron?.[key]) ? 'bg-emerald-400 pulse-dot' : 'bg-zinc-600'"></div>
                            <span class="text-sm text-zinc-300" x-text="label"></span>
                        </div>
                        <div class="text-right">
                            <span class="text-xs text-zinc-500" x-text="data.cron?.[key] ? formatTime(data.cron[key]) : 'Never run'"></span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Entity Summary -->
        <div class="glass rounded-2xl p-5">
            <div class="flex items-center gap-2 mb-4">
                <i data-lucide="database" class="w-4 h-4 text-brand-400"></i>
                <h3 class="text-white font-semibold text-sm">Entity Summary</h3>
            </div>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 rounded-xl bg-white/[0.03]">
                    <div class="flex items-center gap-2">
                        <i data-lucide="mail" class="w-4 h-4 text-zinc-500"></i>
                        <span class="text-sm text-zinc-300">Senders</span>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <span class="text-emerald-400" x-text="(data.entities?.senders_active ?? 0) + ' active'"></span>
                        <span class="text-amber-400" x-text="(data.entities?.senders_paused ?? 0) + ' paused'"></span>
                        <span class="text-zinc-500" x-text="(data.entities?.senders_total ?? 0) + ' total'"></span>
                    </div>
                </div>
                <div class="flex items-center justify-between p-3 rounded-xl bg-white/[0.03]">
                    <div class="flex items-center gap-2">
                        <i data-lucide="inbox" class="w-4 h-4 text-zinc-500"></i>
                        <span class="text-sm text-zinc-300">Seeds</span>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <span class="text-emerald-400" x-text="(data.entities?.seeds_active ?? 0) + ' active'"></span>
                        <span class="text-zinc-500" x-text="(data.entities?.seeds_total ?? 0) + ' total'"></span>
                    </div>
                </div>
                <div class="flex items-center justify-between p-3 rounded-xl bg-white/[0.03]">
                    <div class="flex items-center gap-2">
                        <i data-lucide="flame" class="w-4 h-4 text-zinc-500"></i>
                        <span class="text-sm text-zinc-300">Campaigns</span>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <span class="text-emerald-400" x-text="(data.entities?.campaigns_active ?? 0) + ' active'"></span>
                        <span class="text-amber-400" x-text="(data.entities?.campaigns_paused ?? 0) + ' paused'"></span>
                        <span class="text-zinc-500" x-text="(data.entities?.campaigns_total ?? 0) + ' total'"></span>
                    </div>
                </div>
                <div class="flex items-center justify-between p-3 rounded-xl bg-white/[0.03]">
                    <div class="flex items-center gap-2">
                        <i data-lucide="globe" class="w-4 h-4 text-zinc-500"></i>
                        <span class="text-sm text-zinc-300">Domains</span>
                    </div>
                    <span class="text-zinc-500 text-xs" x-text="data.entities?.domains ?? 0"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Events Today + Activity Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Events Today -->
        <div class="glass rounded-2xl p-5">
            <div class="flex items-center gap-2 mb-4">
                <i data-lucide="calendar" class="w-4 h-4 text-brand-400"></i>
                <h3 class="text-white font-semibold text-sm">Events Today</h3>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div class="text-center p-3 rounded-xl bg-white/[0.03]">
                    <p class="text-xl font-bold text-white" x-text="data.events_today?.total ?? 0"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Total</p>
                </div>
                <div class="text-center p-3 rounded-xl bg-white/[0.03]">
                    <p class="text-xl font-bold text-emerald-400" x-text="data.events_today?.completed ?? 0"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Completed</p>
                </div>
                <div class="text-center p-3 rounded-xl bg-white/[0.03]">
                    <p class="text-xl font-bold text-red-400" x-text="data.events_today?.failed ?? 0"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Failed</p>
                </div>
            </div>
        </div>

        <!-- Recent Alerts -->
        <div class="glass rounded-2xl p-5">
            <div class="flex items-center gap-2 mb-4">
                <i data-lucide="bell" class="w-4 h-4 text-amber-400"></i>
                <h3 class="text-white font-semibold text-sm">Recent Alerts</h3>
            </div>
            <div class="space-y-2 max-h-64 overflow-y-auto">
                <template x-for="a in (data.recent_alerts || [])" :key="a.id">
                    <div class="flex items-start gap-2 p-2.5 rounded-lg bg-white/[0.02]">
                        <div class="w-2 h-2 rounded-full mt-1.5 flex-shrink-0" :class="a.severity === 'critical' ? 'bg-red-500' : a.severity === 'warning' ? 'bg-amber-500' : 'bg-blue-500'"></div>
                        <div class="min-w-0">
                            <p class="text-xs text-zinc-300 font-medium truncate" x-text="a.title"></p>
                            <p class="text-[10px] text-zinc-600 mt-0.5" x-text="formatTime(a.created_at)"></p>
                        </div>
                    </div>
                </template>
                <div x-show="!(data.recent_alerts || []).length" class="text-center py-6">
                    <p class="text-zinc-600 text-xs">No recent alerts</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Section -->
    <div class="glass rounded-2xl p-5">
        <div class="flex items-center gap-2 mb-4">
            <i data-lucide="download" class="w-4 h-4 text-brand-400"></i>
            <h3 class="text-white font-semibold text-sm">Data Export</h3>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="p-4 rounded-xl bg-white/[0.03]">
                <h4 class="text-white text-sm font-medium mb-2">Event Logs CSV</h4>
                <p class="text-zinc-500 text-xs mb-3">Export warmup event logs with filters</p>
                <div class="flex flex-wrap gap-2 mb-3">
                    <input type="date" x-model="exportFrom" class="input-dark px-3 py-2 rounded-lg text-xs text-white" placeholder="From">
                    <input type="date" x-model="exportTo" class="input-dark px-3 py-2 rounded-lg text-xs text-white" placeholder="To">
                </div>
                <a :href="exportUrl()" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-medium text-brand-400 bg-brand-500/10 hover:bg-brand-500/20 transition">
                    <i data-lucide="download" class="w-3.5 h-3.5"></i> Download CSV
                </a>
            </div>
            <div class="p-4 rounded-xl bg-white/[0.03]">
                <h4 class="text-white text-sm font-medium mb-2">Sender Health CSV</h4>
                <p class="text-zinc-500 text-xs mb-3">Export daily sender health log history</p>
                <a href="/api/warmup/export/sender-health" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-medium text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition">
                    <i data-lucide="download" class="w-3.5 h-3.5"></i> Download CSV
                </a>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function systemHealthPage() {
    return {
        data: {}, loading: false, lastRefresh: '—',
        exportFrom: '', exportTo: '',
        readiness: {},
        runningPlanner: false,
        runningScheduler: false,

        get overallOk() {
            return (this.data.queue?.failed_jobs || 0) === 0 &&
                   (this.data.pending_events || 0) < 100 &&
                   (this.data.auto_pause_count || 0) === 0;
        },

        async init() {
            await Promise.all([this.load(), this.loadReadiness()]);
            this.$nextTick(() => lucide.createIcons());
        },

        async load() {
            this.loading = true;
            try {
                this.data = await apiCall('/api/warmup/system-health');
                this.lastRefresh = new Date().toLocaleTimeString();
            } catch(e) { showToast('Failed to load system health', 'error'); }
            this.loading = false;
            this.$nextTick(() => lucide.createIcons());
        },

        async loadReadiness() {
            try {
                this.readiness = await apiCall('/api/warmup/system-health/readiness');
            } catch(e) { /* silent */ }
            this.$nextTick(() => lucide.createIcons());
        },

        async runPlanner() {
            this.runningPlanner = true;
            try {
                const res = await apiCall('/api/warmup/system-health/trigger-planner', 'POST');
                showToast(res.message || 'Planner completed', res.success ? 'success' : 'error');
                await Promise.all([this.load(), this.loadReadiness()]);
            } catch(e) {
                showToast('Planner failed: ' + (e.message || 'Unknown error'), 'error');
            }
            this.runningPlanner = false;
        },

        async runScheduler() {
            this.runningScheduler = true;
            try {
                const res = await apiCall('/api/warmup/system-health/trigger-scheduler', 'POST');
                showToast(res.message || 'Scheduler completed', res.success ? 'success' : 'error');
                await Promise.all([this.load(), this.loadReadiness()]);
            } catch(e) {
                showToast('Scheduler failed: ' + (e.message || 'Unknown error'), 'error');
            }
            this.runningScheduler = false;
        },

        isCronRecent(ts) {
            if (!ts) return false;
            return (Date.now() - new Date(ts).getTime()) < 600000; // 10 minutes
        },

        formatTime(t) {
            if (!t) return '—';
            const d = new Date(t);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        },

        exportUrl() {
            let url = '/api/warmup/export/event-logs';
            const params = [];
            if (this.exportFrom) params.push('from=' + this.exportFrom);
            if (this.exportTo) params.push('to=' + this.exportTo);
            return params.length ? url + '?' + params.join('&') : url;
        }
    };
}
</script>
@endpush
