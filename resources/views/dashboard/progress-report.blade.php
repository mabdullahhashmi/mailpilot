@extends('layouts.app')
@section('title', 'Warmup Progress Report')
@section('page-title', 'Warmup Progress Report')
@section('page-description', 'Campaign progress overview with daily performance trends')

@section('content')
<div x-data="progressReportPage()" x-init="init()">

    <!-- Campaign Selector -->
    <div class="glass rounded-2xl p-4 mb-6">
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-[250px]">
                <label class="block text-xs text-zinc-500 mb-1 font-medium">Select Campaign</label>
                <select x-model="selectedCampaign" @change="loadReport()" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                    <option value="">— Choose a campaign —</option>
                    <template x-for="c in campaigns" :key="c.id">
                        <option :value="c.id" x-text="c.campaign_name"></option>
                    </template>
                </select>
            </div>
            <button @click="printReport()" x-show="report" class="btn-primary px-4 py-2.5 rounded-xl text-sm text-white font-medium flex items-center gap-2 self-end">
                <i data-lucide="printer" class="w-4 h-4"></i> Print Report
            </button>
        </div>
    </div>

    <!-- No Campaign Selected -->
    <div x-show="!selectedCampaign" class="text-center py-20">
        <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-4">
            <i data-lucide="bar-chart-3" class="w-7 h-7 text-zinc-600"></i>
        </div>
        <p class="text-zinc-400 font-medium">Select a campaign to view the progress report</p>
    </div>

    <!-- Report Content -->
    <div x-show="report" id="printable-report">

        <!-- Report Header -->
        <div class="glass rounded-2xl p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-white font-bold text-xl" x-text="report?.campaign_name"></h2>
                    <p class="text-zinc-500 text-xs mt-1">Report generated: <span x-text="portalDate(new Date(), { month: 'long', day: 'numeric', year: 'numeric' })"></span></p>
                </div>
                <div class="sm:text-right">
                    <span class="badge px-3 py-1 rounded-full text-xs"
                          :class="statusColor(report?.status)"
                          x-text="report?.status"></span>
                </div>
            </div>

            <!-- Key Metrics Row -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div class="text-center p-3 rounded-xl bg-white/[0.03]">
                    <p class="text-2xl font-bold text-white" x-text="report?.warmup_day ?? '—'"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Current Day</p>
                </div>
                <div class="text-center p-3 rounded-xl bg-white/[0.03]">
                    <p class="text-2xl font-bold text-brand-400" x-text="report?.total_sent ?? 0"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Total Sent</p>
                </div>
                <div class="text-center p-3 rounded-xl bg-white/[0.03]">
                    <p class="text-2xl font-bold text-emerald-400" x-text="report?.total_completed ?? 0"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Completed</p>
                </div>
                <div class="text-center p-3 rounded-xl bg-white/[0.03]">
                    <p class="text-2xl font-bold text-red-400" x-text="report?.total_failed ?? 0"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Failed</p>
                </div>
                <div class="text-center p-3 rounded-xl bg-white/[0.03]">
                    <p class="text-2xl font-bold" :class="(report?.success_rate ?? 0) >= 90 ? 'text-emerald-400' : (report?.success_rate ?? 0) >= 70 ? 'text-amber-400' : 'text-red-400'" x-text="(report?.success_rate ?? 0) + '%'"></p>
                    <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Success Rate</p>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Daily Volume Chart -->
            <div class="glass rounded-2xl p-5">
                <h3 class="text-white font-semibold text-sm mb-4 flex items-center gap-2">
                    <i data-lucide="bar-chart-3" class="w-4 h-4 text-brand-400"></i> Daily Volume
                </h3>
                <div style="height: 240px;">
                    <canvas id="volumeChart"></canvas>
                </div>
            </div>

            <!-- Success Rate Trend -->
            <div class="glass rounded-2xl p-5">
                <h3 class="text-white font-semibold text-sm mb-4 flex items-center gap-2">
                    <i data-lucide="trending-up" class="w-4 h-4 text-emerald-400"></i> Success Rate Trend
                </h3>
                <div style="height: 240px;">
                    <canvas id="successChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Event Type Breakdown -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="glass rounded-2xl p-5">
                <h3 class="text-white font-semibold text-sm mb-4 flex items-center gap-2">
                    <i data-lucide="pie-chart" class="w-4 h-4 text-amber-400"></i> Event Type Breakdown
                </h3>
                <div style="height: 240px;">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>

            <!-- Active Threads -->
            <div class="glass rounded-2xl p-5">
                <h3 class="text-white font-semibold text-sm mb-4 flex items-center gap-2">
                    <i data-lucide="git-branch" class="w-4 h-4 text-blue-400"></i> Thread Activity
                </h3>
                <div class="space-y-3">
                    <div class="flex items-center justify-between p-3 rounded-xl bg-white/[0.03]">
                        <span class="text-sm text-zinc-300">Active Threads</span>
                        <span class="text-lg font-bold text-white" x-text="report?.active_threads ?? 0"></span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-white/[0.03]">
                        <span class="text-sm text-zinc-300">Total Threads</span>
                        <span class="text-lg font-bold text-white" x-text="report?.total_threads ?? 0"></span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-white/[0.03]">
                        <span class="text-sm text-zinc-300">Reply Rate</span>
                        <span class="text-lg font-bold text-emerald-400" x-text="(report?.reply_rate ?? 0) + '%'"></span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-xl bg-white/[0.03]">
                        <span class="text-sm text-zinc-300">Avg Exec Time</span>
                        <span class="text-lg font-bold text-brand-400" x-text="(report?.avg_exec_time ?? 0) + 'ms'"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daily Activity Table -->
        <div class="glass rounded-2xl overflow-hidden">
            <div class="px-5 py-3 border-b border-white/5 flex items-center gap-2">
                <i data-lucide="table" class="w-4 h-4 text-brand-400"></i>
                <h3 class="text-white font-semibold text-sm">Daily Breakdown</h3>
            </div>
            <table class="w-full">
                <thead>
                    <tr class="border-b border-white/5">
                        <th class="text-left px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Date</th>
                        <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Sent</th>
                        <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Replies</th>
                        <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Opens</th>
                        <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Failed</th>
                        <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Success %</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="day in report?.daily || []" :key="day.date">
                        <tr class="table-row border-b border-white/[0.03]">
                            <td class="px-5 py-3 text-sm text-zinc-300" x-text="day.date"></td>
                            <td class="px-5 py-3 text-center text-sm text-white font-medium" x-text="day.sent || 0"></td>
                            <td class="px-5 py-3 text-center text-sm text-emerald-400" x-text="day.replies || 0"></td>
                            <td class="px-5 py-3 text-center text-sm text-blue-400" x-text="day.opens || 0"></td>
                            <td class="px-5 py-3 text-center text-sm text-red-400" x-text="day.failed || 0"></td>
                            <td class="px-5 py-3 text-center">
                                <span class="text-sm font-semibold" :class="(day.success_rate || 0) >= 90 ? 'text-emerald-400' : (day.success_rate || 0) >= 70 ? 'text-amber-400' : 'text-red-400'" x-text="(day.success_rate || 0) + '%'"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div x-show="!(report?.daily || []).length" class="py-12 text-center text-zinc-600 text-xs">No daily data yet</div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function progressReportPage() {
    return {
        campaigns: [], selectedCampaign: '', report: null,
        volumeChart: null, successChartObj: null, typeChartObj: null,

        async init() {
            await this.loadCampaigns();
            this.$nextTick(() => lucide.createIcons());
        },

        async loadCampaigns() {
            try {
                const res = await apiCall('/api/warmup/campaigns');
                this.campaigns = res.data || res || [];
            } catch(e) { this.campaigns = []; }
        },

        async loadReport() {
            if (!this.selectedCampaign) { this.report = null; return; }
            try {
                const res = await apiCall(`/api/warmup/campaigns/${this.selectedCampaign}/report`);
                this.report = res;
                this.$nextTick(() => {
                    lucide.createIcons();
                    this.renderCharts();
                });
            } catch(e) {
                showToast('Failed to load report: ' + e.message, 'error');
                this.report = null;
            }
        },

        renderCharts() {
            const daily = this.report?.daily || [];

            // Volume chart
            const volCtx = document.getElementById('volumeChart');
            if (volCtx) {
                if (this.volumeChart) this.volumeChart.destroy();
                this.volumeChart = new Chart(volCtx, {
                    type: 'bar',
                    data: {
                        labels: daily.map(d => d.date),
                        datasets: [
                            { label: 'Sent', data: daily.map(d => d.sent || 0), backgroundColor: 'rgba(99,102,241,0.6)', borderRadius: 4 },
                            { label: 'Replies', data: daily.map(d => d.replies || 0), backgroundColor: 'rgba(34,197,94,0.6)', borderRadius: 4 },
                            { label: 'Failed', data: daily.map(d => d.failed || 0), backgroundColor: 'rgba(239,68,68,0.4)', borderRadius: 4 }
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { labels: { color: '#71717a', font: { size: 10 } } } },
                        scales: {
                            x: { ticks: { color: '#52525b', font: { size: 9 }, maxRotation: 45 }, grid: { color: 'rgba(255,255,255,0.03)' } },
                            y: { ticks: { color: '#52525b', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.03)' } }
                        }
                    }
                });
            }

            // Success rate trend
            const sucCtx = document.getElementById('successChart');
            if (sucCtx) {
                if (this.successChartObj) this.successChartObj.destroy();
                this.successChartObj = new Chart(sucCtx, {
                    type: 'line',
                    data: {
                        labels: daily.map(d => d.date),
                        datasets: [{
                            label: 'Success Rate %',
                            data: daily.map(d => d.success_rate || 0),
                            borderColor: '#22c55e',
                            backgroundColor: 'rgba(34,197,94,0.1)',
                            fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { labels: { color: '#71717a', font: { size: 10 } } } },
                        scales: {
                            x: { ticks: { color: '#52525b', font: { size: 9 }, maxRotation: 45 }, grid: { color: 'rgba(255,255,255,0.03)' } },
                            y: { min: 0, max: 100, ticks: { color: '#52525b', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.03)' } }
                        }
                    }
                });
            }

            // Event type breakdown
            const typeCtx = document.getElementById('typeChart');
            if (typeCtx && this.report?.event_types) {
                if (this.typeChartObj) this.typeChartObj.destroy();
                const types = this.report.event_types;
                this.typeChartObj = new Chart(typeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: Object.keys(types).map(t => t.replace(/_/g, ' ')),
                        datasets: [{
                            data: Object.values(types),
                            backgroundColor: ['#6366f1','#22c55e','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#14b8a6'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position: 'right', labels: { color: '#71717a', font: { size: 10 }, padding: 12, usePointStyle: true } } }
                    }
                });
            }
        },

        statusColor(s) {
            const map = { active: 'bg-emerald-500/15 text-emerald-400', paused: 'bg-amber-500/15 text-amber-400', completed: 'bg-blue-500/15 text-blue-400', stopped: 'bg-red-500/15 text-red-400' };
            return map[s] || 'bg-zinc-500/15 text-zinc-400';
        },

        printReport() {
            window.print();
        }
    };
}
</script>
<style>
@media print {
    aside, header, .btn-primary, select, .glass { background: white !important; color: black !important; border: 1px solid #e5e7eb !important; backdrop-filter: none !important; }
    body { background: white !important; color: black !important; }
    .text-white { color: black !important; }
    .text-zinc-300, .text-zinc-400, .text-zinc-500 { color: #4b5563 !important; }
}
</style>
@endpush
