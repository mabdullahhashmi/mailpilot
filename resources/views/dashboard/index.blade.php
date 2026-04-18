@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')
@section('page-description', 'Warmup engine overview and performance metrics')

@section('content')
<div x-data="dashboardPage()" x-init="init()">

    <!-- Stat Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl gradient-brand flex items-center justify-center">
                    <i data-lucide="flame" class="w-5 h-5 text-white"></i>
                </div>
                <span class="badge px-2 py-0.5 rounded-full bg-brand-500/10 text-brand-400" x-text="stats.active_campaigns + ' active'"></span>
            </div>
            <p class="text-2xl font-bold text-white" x-text="stats.active_campaigns">—</p>
            <p class="text-zinc-500 text-xs mt-1">Active Campaigns</p>
        </div>

        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl gradient-success flex items-center justify-center">
                    <i data-lucide="mail-check" class="w-5 h-5 text-white"></i>
                </div>
                <span class="badge px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400" x-text="'today'"></span>
            </div>
            <p class="text-2xl font-bold text-white" x-text="stats.today_events">—</p>
            <p class="text-zinc-500 text-xs mt-1">Events Today</p>
        </div>

        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl gradient-info flex items-center justify-center">
                    <i data-lucide="send" class="w-5 h-5 text-white"></i>
                </div>
                <span class="badge px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-400" x-text="'mailboxes'"></span>
            </div>
            <p class="text-2xl font-bold text-white" x-text="stats.active_senders">—</p>
            <p class="text-zinc-500 text-xs mt-1">Active Senders</p>
        </div>

        <div class="stat-card glass rounded-2xl p-5">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl gradient-warning flex items-center justify-center">
                    <i data-lucide="inbox" class="w-5 h-5 text-white"></i>
                </div>
                <span class="badge px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-400" x-text="'seeds'"></span>
            </div>
            <p class="text-2xl font-bold text-white" x-text="stats.active_seeds">—</p>
            <p class="text-zinc-500 text-xs mt-1">Active Seeds</p>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
        <!-- Activity Chart -->
        <div class="xl:col-span-2 glass rounded-2xl p-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h3 class="text-white font-semibold text-base">Activity Overview</h3>
                    <p class="text-zinc-500 text-xs mt-0.5">Daily warmup event volume (last 14 days)</p>
                </div>
                <div class="flex gap-2">
                    <span class="flex items-center gap-1.5 text-xs text-zinc-400">
                        <span class="w-2 h-2 rounded-full bg-brand-400"></span> Events
                    </span>
                    <span class="flex items-center gap-1.5 text-xs text-zinc-400">
                        <span class="w-2 h-2 rounded-full bg-emerald-400"></span> Threads
                    </span>
                </div>
            </div>
            <div style="height: 260px;">
                <canvas id="activityChart"></canvas>
            </div>
        </div>

        <!-- Readiness Panel -->
        <div class="glass rounded-2xl p-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <h3 class="text-white font-semibold text-base">Sender Readiness</h3>
                <i data-lucide="shield-check" class="w-4 h-4 text-zinc-500"></i>
            </div>
            <div class="space-y-3" x-show="readiness.length > 0">
                <template x-for="sender in readiness" :key="sender.id">
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-white/[0.03] border border-white/5">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-[10px] font-bold"
                             :style="'background:' + sender.readiness_color">
                            <span x-text="sender.progress_percent + '%'"></span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-white font-medium truncate" x-text="sender.email"></p>
                            <div class="flex items-center gap-2 mt-1">
                                <div class="flex-1 h-1.5 rounded-full bg-white/10 overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500" :style="'width:' + sender.progress_percent + '%; background:' + sender.readiness_color"></div>
                                </div>
                                <span class="text-[10px] font-semibold uppercase tracking-wider" :style="'color:' + sender.readiness_color" x-text="sender.readiness_label"></span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <div x-show="readiness.length === 0" class="text-center py-8">
                <i data-lucide="inbox" class="w-8 h-8 text-zinc-700 mx-auto mb-2"></i>
                <p class="text-zinc-600 text-sm">No active senders yet</p>
                <a href="{{ route('dashboard.senders') }}" class="text-brand-400 text-xs mt-1 hover:underline">Add your first sender</a>
            </div>
        </div>
    </div>

    <!-- Weekly Summary + Quick Actions -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <!-- Weekly Summary -->
        <div class="glass rounded-2xl p-6">
            <h3 class="text-white font-semibold text-base mb-4">This Week</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5 text-center">
                    <p class="text-xl font-bold text-white" x-text="weekly.total_events ?? 0"></p>
                    <p class="text-zinc-500 text-xs mt-1">Total Events</p>
                </div>
                <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5 text-center">
                    <p class="text-xl font-bold text-white" x-text="weekly.new_threads ?? 0"></p>
                    <p class="text-zinc-500 text-xs mt-1">New Threads</p>
                </div>
                <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5 text-center">
                    <p class="text-xl font-bold text-white" x-text="weekly.active_campaigns ?? 0"></p>
                    <p class="text-zinc-500 text-xs mt-1">Campaigns</p>
                </div>
                <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5 text-center">
                    <p class="text-xl font-bold text-emerald-400" x-text="weekly.period ?? '—'"></p>
                    <p class="text-zinc-500 text-xs mt-1">Period</p>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="glass rounded-2xl p-6">
            <h3 class="text-white font-semibold text-base mb-4">Quick Actions</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <a href="{{ route('dashboard.senders') }}" class="flex items-center gap-3 p-4 rounded-xl bg-white/[0.03] border border-white/5 hover:border-brand-500/30 hover:bg-brand-500/5 transition group">
                    <div class="w-9 h-9 rounded-lg gradient-brand flex items-center justify-center">
                        <i data-lucide="plus" class="w-4 h-4 text-white"></i>
                    </div>
                    <div>
                        <p class="text-sm text-white font-medium group-hover:text-brand-300">Add Sender</p>
                        <p class="text-zinc-600 text-[10px]">New mailbox</p>
                    </div>
                </a>
                <a href="{{ route('dashboard.seeds') }}" class="flex items-center gap-3 p-4 rounded-xl bg-white/[0.03] border border-white/5 hover:border-emerald-500/30 hover:bg-emerald-500/5 transition group">
                    <div class="w-9 h-9 rounded-lg gradient-success flex items-center justify-center">
                        <i data-lucide="plus" class="w-4 h-4 text-white"></i>
                    </div>
                    <div>
                        <p class="text-sm text-white font-medium group-hover:text-emerald-300">Add Seed</p>
                        <p class="text-zinc-600 text-[10px]">Seed inbox</p>
                    </div>
                </a>
                <a href="{{ route('dashboard.campaigns') }}" class="flex items-center gap-3 p-4 rounded-xl bg-white/[0.03] border border-white/5 hover:border-amber-500/30 hover:bg-amber-500/5 transition group">
                    <div class="w-9 h-9 rounded-lg gradient-warning flex items-center justify-center">
                        <i data-lucide="flame" class="w-4 h-4 text-white"></i>
                    </div>
                    <div>
                        <p class="text-sm text-white font-medium group-hover:text-amber-300">New Campaign</p>
                        <p class="text-zinc-600 text-[10px]">Start warmup</p>
                    </div>
                </a>
                <a href="{{ route('dashboard.domains') }}" class="flex items-center gap-3 p-4 rounded-xl bg-white/[0.03] border border-white/5 hover:border-blue-500/30 hover:bg-blue-500/5 transition group">
                    <div class="w-9 h-9 rounded-lg gradient-info flex items-center justify-center">
                        <i data-lucide="globe" class="w-4 h-4 text-white"></i>
                    </div>
                    <div>
                        <p class="text-sm text-white font-medium group-hover:text-blue-300">Add Domain</p>
                        <p class="text-zinc-600 text-[10px]">DNS check</p>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function dashboardPage() {
    return {
        stats: { active_campaigns: 0, today_events: 0, active_senders: 0, active_seeds: 0 },
        weekly: {},
        readiness: [],
        chartData: [],

        async init() {
            try {
                const [overview, readiness, chart] = await Promise.all([
                    apiCall('/api/warmup/dashboard'),
                    apiCall('/api/warmup/dashboard/readiness'),
                    apiCall('/api/warmup/dashboard/activity-chart?days=14'),
                ]);

                this.stats = {
                    active_campaigns: overview.weekly?.active_campaigns ?? 0,
                    today_events: overview.today?.total_events ?? 0,
                    active_senders: overview.weekly?.active_senders ?? 0,
                    active_seeds: overview.weekly?.active_seeds ?? 0,
                };
                this.weekly = overview.weekly ?? {};
                this.readiness = readiness ?? [];
                this.chartData = chart ?? [];

                this.$nextTick(() => this.renderChart());
            } catch (e) {
                console.log('Dashboard data not yet available:', e.message);
            }
            this.$nextTick(() => lucide.createIcons());
        },

        renderChart() {
            const ctx = document.getElementById('activityChart');
            if (!ctx) return;

            const labels = this.chartData.map(d => d.date?.substring(5) || '');
            const events = this.chartData.map(d => d.total_events || 0);
            const threads = this.chartData.map(d => d.unique_threads || 0);

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Events',
                            data: events,
                            borderColor: '#818cf8',
                            backgroundColor: 'rgba(129,140,248,0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2,
                            pointRadius: 0,
                            pointHoverRadius: 4,
                        },
                        {
                            label: 'Threads',
                            data: threads,
                            borderColor: '#34d399',
                            backgroundColor: 'rgba(52,211,153,0.05)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2,
                            pointRadius: 0,
                            pointHoverRadius: 4,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { color: 'rgba(255,255,255,0.03)' }, ticks: { color: '#52525b', font: { size: 10 } } },
                        y: { grid: { color: 'rgba(255,255,255,0.03)' }, ticks: { color: '#52525b', font: { size: 10 } }, beginAtZero: true }
                    },
                    interaction: { intersect: false, mode: 'index' },
                }
            });
        }
    };
}
</script>
@endpush
