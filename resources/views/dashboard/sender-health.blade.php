@extends('layouts.app')
@section('title', 'Sender Health')
@section('page-title', 'Sender Health')
@section('page-description', 'Health scores, reputation trends, and per-sender diagnostics')

@section('content')
<div x-data="senderHealthPage()" x-init="init()">

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl gradient-brand flex items-center justify-center">
                    <i data-lucide="mail" class="w-5 h-5 text-white"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-white" x-text="senders.length"></p>
            <p class="text-zinc-500 text-xs mt-1 uppercase tracking-wider">Total Senders</p>
        </div>
        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl gradient-success flex items-center justify-center">
                    <i data-lucide="shield-check" class="w-5 h-5 text-white"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-emerald-400" x-text="summary.healthy"></p>
            <p class="text-zinc-500 text-xs mt-1 uppercase tracking-wider">Healthy (&ge;80)</p>
        </div>
        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl gradient-warning flex items-center justify-center">
                    <i data-lucide="alert-triangle" class="w-5 h-5 text-white"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-amber-400" x-text="summary.warning"></p>
            <p class="text-zinc-500 text-xs mt-1 uppercase tracking-wider">Warning (50-79)</p>
        </div>
        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-xl gradient-danger flex items-center justify-center">
                    <i data-lucide="shield-x" class="w-5 h-5 text-white"></i>
                </div>
            </div>
            <p class="text-2xl font-bold text-red-400" x-text="summary.critical"></p>
            <p class="text-zinc-500 text-xs mt-1 uppercase tracking-wider">Critical (&lt;50)</p>
        </div>
    </div>

    <!-- Export Button -->
    <div class="flex items-center justify-between mb-4">
        <span class="text-zinc-500 text-sm" x-text="senders.length + ' sender mailboxes'"></span>
        <a href="/api/warmup/export/sender-health" class="flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-medium text-brand-400 bg-brand-500/10 hover:bg-brand-500/20 transition">
            <i data-lucide="download" class="w-3.5 h-3.5"></i> Export CSV
        </a>
    </div>

    <!-- Sender Health Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-8">
        <template x-for="s in senders" :key="s.id">
            <div class="glass rounded-2xl p-5 hover:border-white/10 transition cursor-pointer" @click="selectSender(s.id)">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                             :class="s.health_score >= 80 ? 'bg-emerald-500/15' : s.health_score >= 50 ? 'bg-amber-500/15' : 'bg-red-500/15'">
                            <i data-lucide="mail" class="w-5 h-5"
                               :class="s.health_score >= 80 ? 'text-emerald-400' : s.health_score >= 50 ? 'text-amber-400' : 'text-red-400'"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-white font-semibold text-sm truncate" x-text="s.email"></p>
                            <p class="text-zinc-600 text-xs" x-text="s.provider_type || 'custom'"></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-bold" :class="s.health_score >= 80 ? 'text-emerald-400' : s.health_score >= 50 ? 'text-amber-400' : 'text-red-400'" x-text="s.health_score ?? '—'"></p>
                        <p class="text-zinc-600 text-[10px] uppercase tracking-wider">Score</p>
                    </div>
                </div>

                <!-- Health Bar -->
                <div class="mb-4">
                    <div class="w-full h-2 rounded-full bg-white/10 overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-700"
                             :class="s.health_score >= 80 ? 'bg-emerald-500' : s.health_score >= 50 ? 'bg-amber-500' : 'bg-red-500'"
                             :style="'width:' + (s.health_score || 0) + '%'"></div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="grid grid-cols-3 gap-2">
                    <div class="text-center p-2 rounded-lg bg-white/[0.03]">
                        <p class="text-sm font-semibold text-white" x-text="s.stats?.total_sent ?? 0"></p>
                        <p class="text-[10px] text-zinc-500 uppercase tracking-wider">Sent 30d</p>
                    </div>
                    <div class="text-center p-2 rounded-lg bg-white/[0.03]">
                        <p class="text-sm font-semibold text-emerald-400" x-text="(s.stats?.reply_rate ?? 0) + '%'"></p>
                        <p class="text-[10px] text-zinc-500 uppercase tracking-wider">Reply</p>
                    </div>
                    <div class="text-center p-2 rounded-lg bg-white/[0.03]">
                        <p class="text-sm font-semibold text-red-400" x-text="(s.stats?.bounce_rate ?? 0) + '%'"></p>
                        <p class="text-[10px] text-zinc-500 uppercase tracking-wider">Bounce</p>
                    </div>
                </div>

                <div class="mt-3 flex items-center gap-2">
                    <span class="badge px-2 py-0.5 rounded-full"
                          :class="s.status === 'active' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-zinc-500/15 text-zinc-400'"
                          x-text="s.status"></span>
                    <span class="text-[10px] text-zinc-600" x-text="s.stats?.avg_health ? 'Avg: ' + s.stats.avg_health.toFixed(0) : ''"></span>
                </div>
            </div>
        </template>
    </div>

    <!-- Empty State -->
    <div x-show="senders.length === 0 && !loading" class="text-center py-20">
        <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-4">
            <i data-lucide="activity" class="w-7 h-7 text-zinc-600"></i>
        </div>
        <p class="text-zinc-400 font-medium">No sender health data yet</p>
        <p class="text-zinc-600 text-sm mt-1">Health data will appear once warmup campaigns are active</p>
    </div>

    <!-- Detail Modal -->
    <div x-show="showDetail" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showDetail = false">
        <div class="w-full max-w-2xl glass rounded-2xl p-6 fade-in max-h-[85vh] overflow-y-auto" @click.stop>
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-white font-semibold text-lg" x-text="detail.email || 'Sender Detail'"></h3>
                    <p class="text-zinc-500 text-xs mt-0.5" x-text="detail.provider_type || 'custom'"></p>
                </div>
                <button @click="showDetail = false" class="btn-ghost p-2 rounded-lg text-zinc-400">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <!-- Detail Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="glass-light rounded-xl p-3 text-center">
                    <p class="text-xl font-bold" :class="(detail.health_score || 0) >= 80 ? 'text-emerald-400' : (detail.health_score || 0) >= 50 ? 'text-amber-400' : 'text-red-400'" x-text="detail.health_score ?? '—'"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider">Health</p>
                </div>
                <div class="glass-light rounded-xl p-3 text-center">
                    <p class="text-xl font-bold text-white" x-text="detail.stats?.total_sent ?? 0"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider">Sent</p>
                </div>
                <div class="glass-light rounded-xl p-3 text-center">
                    <p class="text-xl font-bold text-emerald-400" x-text="detail.stats?.total_replied ?? 0"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider">Replied</p>
                </div>
                <div class="glass-light rounded-xl p-3 text-center">
                    <p class="text-xl font-bold text-red-400" x-text="detail.stats?.total_bounced ?? 0"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider">Bounced</p>
                </div>
            </div>

            <!-- Health Trend Chart -->
            <div class="glass-light rounded-xl p-4 mb-6">
                <h4 class="text-zinc-400 text-xs font-semibold uppercase tracking-wider mb-3">Health Trend (60 Days)</h4>
                <div style="height: 220px;">
                    <canvas id="healthTrendChart"></canvas>
                </div>
            </div>

            <!-- Daily History Table -->
            <div class="glass-light rounded-xl overflow-hidden">
                <h4 class="text-zinc-400 text-xs font-semibold uppercase tracking-wider px-4 pt-4 mb-2">Daily History</h4>
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-600">Date</th>
                            <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-600">Day</th>
                            <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-600">Sent</th>
                            <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-600">Replied</th>
                            <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-600">Failed</th>
                            <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-600">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="h in detail.health_history || []" :key="h.id">
                            <tr class="table-row border-b border-white/[0.03]">
                                <td class="px-4 py-2 text-xs text-zinc-300" x-text="h.log_date"></td>
                                <td class="px-4 py-2 text-xs text-zinc-500" x-text="'Day ' + (h.warmup_day || '—')"></td>
                                <td class="px-4 py-2 text-xs text-white font-medium" x-text="h.sent_today || 0"></td>
                                <td class="px-4 py-2 text-xs text-emerald-400" x-text="h.replied_today || 0"></td>
                                <td class="px-4 py-2 text-xs text-red-400" x-text="h.failed_events || 0"></td>
                                <td class="px-4 py-2">
                                    <span class="text-xs font-bold" :class="(h.health_score || 0) >= 80 ? 'text-emerald-400' : (h.health_score || 0) >= 50 ? 'text-amber-400' : 'text-red-400'" x-text="h.health_score ?? '—'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="!detail.health_history?.length" class="py-8 text-center text-zinc-600 text-xs">No history data</div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function senderHealthPage() {
    return {
        senders: [], loading: true, showDetail: false, detail: {},
        summary: { healthy: 0, warning: 0, critical: 0 },
        healthChart: null,

        async init() {
            await this.load();
            this.$nextTick(() => lucide.createIcons());
        },

        async load() {
            this.loading = true;
            try {
                const data = await apiCall('/api/warmup/sender-health');
                this.senders = data.senders || data || [];
                this.summary = {
                    healthy: this.senders.filter(s => (s.health_score || 0) >= 80).length,
                    warning: this.senders.filter(s => (s.health_score || 0) >= 50 && (s.health_score || 0) < 80).length,
                    critical: this.senders.filter(s => (s.health_score || 0) < 50).length,
                };
            } catch(e) { this.senders = []; }
            this.loading = false;
            this.$nextTick(() => lucide.createIcons());
        },

        async selectSender(id) {
            try {
                const data = await apiCall(`/api/warmup/sender-health/${id}`);
                this.detail = data;
                this.showDetail = true;
                this.$nextTick(() => {
                    lucide.createIcons();
                    this.renderChart();
                });
            } catch(e) { showToast('Failed to load detail', 'error'); }
        },

        renderChart() {
            const ctx = document.getElementById('healthTrendChart');
            if (!ctx) return;
            if (this.healthChart) this.healthChart.destroy();

            const history = (this.detail.health_history || []).slice().reverse();
            const labels = history.map(h => h.log_date);
            const scores = history.map(h => h.health_score);
            const sent = history.map(h => h.sent_today || 0);

            this.healthChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Health Score',
                            data: scores,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99,102,241,0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                        },
                        {
                            label: 'Sent',
                            data: sent,
                            borderColor: '#22c55e',
                            backgroundColor: 'transparent',
                            borderDash: [5, 5],
                            tension: 0.4,
                            pointRadius: 0,
                            yAxisID: 'y1',
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#71717a', font: { size: 11 } } }
                    },
                    scales: {
                        x: { ticks: { color: '#52525b', font: { size: 10 }, maxRotation: 45 }, grid: { color: 'rgba(255,255,255,0.03)' } },
                        y: { min: 0, max: 100, ticks: { color: '#52525b', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.03)' } },
                        y1: { position: 'right', ticks: { color: '#52525b', font: { size: 10 } }, grid: { display: false } }
                    }
                }
            });
        }
    };
}
</script>
@endpush
