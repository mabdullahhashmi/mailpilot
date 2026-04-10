@extends('layouts.app')
@section('title', 'Campaign Detail')
@section('page-title', 'Campaign Detail')
@section('page-description', 'Analytics, events, and thread timeline for this campaign')

@section('content')
<div x-data="campaignDetail()" x-init="init()">

    <!-- Loading -->
    <div x-show="loading" class="text-center py-20">
        <div class="w-12 h-12 rounded-xl glass flex items-center justify-center mx-auto mb-3 shimmer">
            <i data-lucide="loader" class="w-5 h-5 text-zinc-500 animate-spin"></i>
        </div>
        <p class="text-zinc-500 text-sm">Loading campaign data...</p>
    </div>

    <div x-show="!loading" x-cloak>

        <!-- Back + Campaign Header -->
        <div class="flex items-center gap-3 mb-6">
            <a href="/campaigns" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-white">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div class="flex-1">
                <h3 class="text-white font-semibold text-lg" x-text="campaign.campaign_name || 'Campaign'"></h3>
                <p class="text-zinc-500 text-xs" x-text="'Day ' + (campaign.current_day_number || 1) + ' • ' + (campaign.status || '—')"></p>
            </div>
            <span class="badge px-3 py-1.5 rounded-full text-xs font-semibold uppercase tracking-widest"
                  :class="statusBadge(campaign.status)" x-text="campaign.status"></span>
        </div>

        <!-- Stat Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="stat-card glass rounded-2xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-9 h-9 rounded-lg gradient-brand flex items-center justify-center"><i data-lucide="send" class="w-4 h-4 text-white"></i></div>
                    <span class="text-zinc-500 text-xs font-medium">Total Events</span>
                </div>
                <p class="text-2xl font-bold text-white" x-text="report.total_events ?? 0"></p>
            </div>
            <div class="stat-card glass rounded-2xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-9 h-9 rounded-lg gradient-success flex items-center justify-center"><i data-lucide="check-circle" class="w-4 h-4 text-white"></i></div>
                    <span class="text-zinc-500 text-xs font-medium">Completed</span>
                </div>
                <p class="text-2xl font-bold text-emerald-400" x-text="report.completed ?? 0"></p>
            </div>
            <div class="stat-card glass rounded-2xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-9 h-9 rounded-lg gradient-danger flex items-center justify-center"><i data-lucide="alert-triangle" class="w-4 h-4 text-white"></i></div>
                    <span class="text-zinc-500 text-xs font-medium">Failed</span>
                </div>
                <p class="text-2xl font-bold text-red-400" x-text="report.failed ?? 0"></p>
            </div>
            <div class="stat-card glass rounded-2xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-9 h-9 rounded-lg gradient-info flex items-center justify-center"><i data-lucide="percent" class="w-4 h-4 text-white"></i></div>
                    <span class="text-zinc-500 text-xs font-medium">Success Rate</span>
                </div>
                <p class="text-2xl font-bold text-blue-400" x-text="(report.success_rate ?? 0) + '%'"></p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 mb-6 border-b border-white/5 pb-px">
            <template x-for="t in ['overview','threads','events']" :key="t">
                <button @click="tab = t" class="px-4 py-2.5 text-sm font-medium rounded-t-lg transition"
                        :class="tab === t ? 'text-white bg-white/5 border-b-2 border-brand-500' : 'text-zinc-500 hover:text-zinc-300'"
                        x-text="t.charAt(0).toUpperCase() + t.slice(1)"></button>
            </template>
        </div>

        <!-- Overview Tab -->
        <div x-show="tab === 'overview'">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Activity Chart -->
                <div class="glass rounded-2xl p-5">
                    <h4 class="text-white font-semibold text-sm mb-4">Daily Activity</h4>
                    <div class="h-64">
                        <canvas id="activityChart"></canvas>
                    </div>
                </div>

                <!-- Campaign Info -->
                <div class="glass rounded-2xl p-5">
                    <h4 class="text-white font-semibold text-sm mb-4">Campaign Info</h4>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Sender</span>
                            <span class="text-white text-sm font-medium" x-text="campaign.sender_mailbox?.email_address || '—'"></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Profile</span>
                            <span class="text-white text-sm font-medium" x-text="campaign.profile?.profile_name || '—'"></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Current Stage</span>
                            <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                  :class="campaign.current_stage === 'ramp_up' ? 'bg-brand-500/15 text-brand-400' : campaign.current_stage === 'plateau' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'"
                                  x-text="(campaign.current_stage || '—').replace('_',' ')"></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Day</span>
                            <span class="text-white text-sm font-medium" x-text="(campaign.current_day_number || 1) + ' / ' + (campaign.profile?.total_days || '—')"></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Time Window</span>
                            <span class="text-white text-sm font-medium" x-text="(campaign.time_window_start || '08:00') + ' — ' + (campaign.time_window_end || '22:00')"></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Threads</span>
                            <span class="text-white text-sm font-medium" x-text="report.threads ?? 0"></span>
                        </div>
                        <div class="flex items-center justify-between py-2">
                            <span class="text-zinc-500 text-sm">Pending Events</span>
                            <span class="text-amber-400 text-sm font-medium" x-text="report.pending ?? 0"></span>
                        </div>
                    </div>
                </div>

                <!-- Readiness Score -->
                <div class="glass rounded-2xl p-5">
                    <h4 class="text-white font-semibold text-sm mb-4">Sender Readiness</h4>
                    <div class="flex items-center gap-6">
                        <div class="relative w-24 h-24">
                            <svg class="transform -rotate-90 w-24 h-24" viewBox="0 0 36 36">
                                <path d="M18 2.0845a15.9155 15.9155 0 010 31.831 15.9155 15.9155 0 010-31.831" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="3"/>
                                <path d="M18 2.0845a15.9155 15.9155 0 010 31.831 15.9155 15.9155 0 010-31.831" fill="none"
                                      :stroke="readiness.overall >= 70 ? '#22c55e' : readiness.overall >= 40 ? '#f59e0b' : '#ef4444'"
                                      stroke-width="3" stroke-linecap="round"
                                      :stroke-dasharray="readiness.overall + ', 100'"/>
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <span class="text-xl font-bold text-white" x-text="readiness.overall ?? 0"></span>
                            </div>
                        </div>
                        <div class="flex-1 space-y-2 text-sm">
                            <template x-for="(val, key) in readiness.breakdown || {}" :key="key">
                                <div class="flex items-center justify-between">
                                    <span class="text-zinc-500 capitalize" x-text="key.replace(/_/g,' ')"></span>
                                    <span class="text-white font-medium" x-text="val"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Event Type Breakdown -->
                <div class="glass rounded-2xl p-5">
                    <h4 class="text-white font-semibold text-sm mb-4">Event Breakdown</h4>
                    <div class="h-64">
                        <canvas id="breakdownChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Threads Tab -->
        <div x-show="tab === 'threads'">
            <div class="glass rounded-2xl overflow-hidden">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Thread</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Seed</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Status</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Messages</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Subject</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="th in campaign.threads || []" :key="th.id">
                            <tr class="table-row border-b border-white/[0.03]">
                                <td class="px-5 py-3 text-sm text-white font-medium" x-text="'#' + th.id"></td>
                                <td class="px-5 py-3 text-sm text-zinc-400" x-text="th.seed_mailbox?.email_address || '—'"></td>
                                <td class="px-5 py-3">
                                    <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                          :class="th.status === 'active' ? 'bg-emerald-500/15 text-emerald-400' : th.status === 'completed' ? 'bg-brand-500/15 text-brand-400' : 'bg-zinc-500/15 text-zinc-400'"
                                          x-text="th.status"></span>
                                </td>
                                <td class="px-5 py-3 text-sm text-zinc-400" x-text="(th.actual_message_count || 0) + ' / ' + (th.planned_message_count || '—')"></td>
                                <td class="px-5 py-3 text-sm text-zinc-400 truncate max-w-[180px]" x-text="th.subject || '—'"></td>
                                <td class="px-5 py-3 text-sm text-zinc-500" x-text="new Date(th.created_at).toLocaleDateString()"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="!campaign.threads?.length" class="text-center py-12">
                    <p class="text-zinc-500 text-sm">No threads yet</p>
                </div>
            </div>
        </div>

        <!-- Events Tab -->
        <div x-show="tab === 'events'">
            <div class="glass rounded-2xl overflow-hidden">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">ID</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Type</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Status</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Scheduled</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Executed</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="ev in events" :key="ev.id">
                            <tr class="table-row border-b border-white/[0.03]">
                                <td class="px-5 py-3 text-sm text-white font-medium" x-text="'#' + ev.id"></td>
                                <td class="px-5 py-3">
                                    <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                          :class="ev.event_type === 'send_initial' ? 'bg-brand-500/15 text-brand-400' : ev.event_type === 'send_reply' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'"
                                          x-text="(ev.event_type || '').replace(/_/g,' ')"></span>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                          :class="ev.status === 'completed' ? 'bg-emerald-500/15 text-emerald-400' : ev.status === 'failed' ? 'bg-red-500/15 text-red-400' : ev.status === 'pending' ? 'bg-amber-500/15 text-amber-400' : 'bg-zinc-500/15 text-zinc-400'"
                                          x-text="ev.status"></span>
                                </td>
                                <td class="px-5 py-3 text-sm text-zinc-400" x-text="ev.scheduled_at ? new Date(ev.scheduled_at).toLocaleString() : '—'"></td>
                                <td class="px-5 py-3 text-sm text-zinc-400" x-text="ev.executed_at ? new Date(ev.executed_at).toLocaleString() : '—'"></td>
                                <td class="px-5 py-3 text-sm text-red-400/80 truncate max-w-[200px]" x-text="ev.error_message || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="events.length === 0" class="text-center py-12">
                    <p class="text-zinc-500 text-sm">No events found</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function campaignDetail() {
    return {
        loading: true, campaign: {}, report: {}, readiness: {}, events: [], tab: 'overview',
        actChart: null, breakdownChart: null,

        get campaignId() {
            return window.location.pathname.split('/').filter(Boolean).pop();
        },

        statusBadge(s) {
            return { active: 'bg-emerald-500/15 text-emerald-400', paused: 'bg-amber-500/15 text-amber-400', draft: 'bg-zinc-500/15 text-zinc-400', stopped: 'bg-red-500/15 text-red-400', completed: 'bg-brand-500/15 text-brand-400' }[s] || 'bg-zinc-500/15 text-zinc-400';
        },

        async init() {
            try {
                const data = await apiCall(`/api/warmup/campaigns/${this.campaignId}`);
                this.campaign = data.campaign || {};
                this.report = data.report || {};
                this.readiness = data.readiness || {};

                // Load events from event-logs filtered by campaign
                try {
                    const logData = await apiCall(`/api/warmup/event-logs?campaign_id=${this.campaignId}`);
                    this.events = Array.isArray(logData) ? logData : (logData.data || []);
                } catch(e) { this.events = []; }

            } catch(e) { showToast('Failed to load campaign: ' + e.message, 'error'); }
            this.loading = false;
            this.$nextTick(() => {
                lucide.createIcons();
                this.renderCharts();
            });
        },

        renderCharts() {
            // Activity chart - daily data
            const actCtx = document.getElementById('activityChart')?.getContext('2d');
            if (actCtx) {
                const dailyData = this.buildDailyData();
                this.actChart = new Chart(actCtx, {
                    type: 'bar',
                    data: {
                        labels: dailyData.labels,
                        datasets: [
                            { label: 'Sent', data: dailyData.sent, backgroundColor: 'rgba(99,102,241,0.7)', borderRadius: 4 },
                            { label: 'Completed', data: dailyData.completed, backgroundColor: 'rgba(34,197,94,0.7)', borderRadius: 4 },
                            { label: 'Failed', data: dailyData.failed, backgroundColor: 'rgba(239,68,68,0.7)', borderRadius: 4 },
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#a1a1aa', font: { size: 11 } } } }, scales: { x: { ticks: { color: '#71717a', font: { size: 10 } }, grid: { display: false } }, y: { ticks: { color: '#71717a', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' } } } }
                });
            }

            // Breakdown doughnut
            const bCtx = document.getElementById('breakdownChart')?.getContext('2d');
            if (bCtx) {
                const types = this.countByType();
                this.breakdownChart = new Chart(bCtx, {
                    type: 'doughnut',
                    data: {
                        labels: Object.keys(types).map(k => k.replace(/_/g, ' ')),
                        datasets: [{ data: Object.values(types), backgroundColor: ['#6366f1','#22c55e','#f59e0b','#ef4444','#3b82f6','#8b5cf6'], borderWidth: 0 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { color: '#a1a1aa', font: { size: 11 }, padding: 16 } } } }
                });
            }
        },

        buildDailyData() {
            const map = {};
            (this.events || []).forEach(ev => {
                const d = ev.scheduled_at ? ev.scheduled_at.substring(0, 10) : (ev.created_at || '').substring(0, 10);
                if (!d) return;
                if (!map[d]) map[d] = { sent: 0, completed: 0, failed: 0 };
                map[d].sent++;
                if (ev.status === 'completed') map[d].completed++;
                if (ev.status === 'failed') map[d].failed++;
            });
            const sorted = Object.keys(map).sort();
            return {
                labels: sorted.map(d => d.substring(5)),
                sent: sorted.map(d => map[d].sent),
                completed: sorted.map(d => map[d].completed),
                failed: sorted.map(d => map[d].failed),
            };
        },

        countByType() {
            const counts = {};
            (this.events || []).forEach(ev => {
                const t = ev.event_type || 'unknown';
                counts[t] = (counts[t] || 0) + 1;
            });
            return counts;
        }
    };
}
</script>
@endpush
