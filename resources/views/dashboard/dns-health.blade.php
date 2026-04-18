@extends('layouts.app')
@section('title', 'DNS Health')
@section('page-title', 'DNS & Blacklist Health')
@section('page-description', 'Domain DNS authentication status and blacklist monitoring')

@section('content')
<div x-data="dnsHealthPage()" x-init="init()">

    <!-- Summary Stats -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <div class="stat-card glass rounded-2xl p-4 text-center">
            <p class="text-2xl font-bold text-white" x-text="domains.length"></p>
            <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Domains</p>
        </div>
        <div class="stat-card glass rounded-2xl p-4 text-center">
            <p class="text-2xl font-bold text-emerald-400" x-text="summary.healthy"></p>
            <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">All Pass</p>
        </div>
        <div class="stat-card glass rounded-2xl p-4 text-center">
            <p class="text-2xl font-bold text-amber-400" x-text="summary.warnings"></p>
            <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Warnings</p>
        </div>
        <div class="stat-card glass rounded-2xl p-4 text-center">
            <p class="text-2xl font-bold text-red-400" x-text="summary.failing"></p>
            <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Failing</p>
        </div>
        <div class="stat-card glass rounded-2xl p-4 text-center">
            <p class="text-2xl font-bold" :class="blacklistHits > 0 ? 'text-red-400' : 'text-emerald-400'" x-text="blacklistHits"></p>
            <p class="text-zinc-500 text-[10px] uppercase tracking-wider mt-1">Blacklisted</p>
        </div>
    </div>

    <!-- Actions -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <span class="text-zinc-500 text-sm">DNS authentication and blacklist status for all domains</span>
        <div class="flex flex-wrap gap-2 w-full sm:w-auto sm:justify-end">
            <button @click="checkAllDns()" :disabled="checkingAll" class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-medium text-white btn-primary">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5" :class="checkingAll && 'animate-spin'"></i>
                <span x-text="checkingAll ? 'Checking...' : 'Check All DNS'"></span>
            </button>
            <button @click="checkAllBlacklists()" :disabled="checkingBl" class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-medium text-amber-400 bg-amber-500/10 hover:bg-amber-500/20 transition">
                <i data-lucide="shield-alert" class="w-3.5 h-3.5" :class="checkingBl && 'animate-spin'"></i>
                <span x-text="checkingBl ? 'Scanning...' : 'Blacklist Scan'"></span>
            </button>
        </div>
    </div>

    <!-- DNS Status Table -->
    <div class="glass rounded-2xl overflow-hidden mb-8">
        <div class="px-5 py-3 border-b border-white/5 flex items-center gap-2">
            <i data-lucide="shield" class="w-4 h-4 text-brand-400"></i>
            <h3 class="text-white font-semibold text-sm">DNS Authentication</h3>
        </div>
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/5">
                    <th class="text-left px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Domain</th>
                    <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">SPF</th>
                    <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">DKIM</th>
                    <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">DMARC</th>
                    <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">MX</th>
                    <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Score</th>
                    <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Checked</th>
                    <th class="text-center px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Action</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="d in domains" :key="d.id">
                    <tr class="table-row border-b border-white/[0.03]">
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2">
                                <i data-lucide="globe" class="w-4 h-4 text-zinc-500"></i>
                                <span class="text-sm text-white font-medium" x-text="d.domain_name"></span>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span class="inline-flex items-center gap-1 badge px-2 py-0.5 rounded-full"
                                  :class="dnsBadge(d.spf_status)" x-text="d.spf_status || 'unchecked'"></span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span class="inline-flex items-center gap-1 badge px-2 py-0.5 rounded-full"
                                  :class="dnsBadge(d.dkim_status)" x-text="d.dkim_status || 'unchecked'"></span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span class="inline-flex items-center gap-1 badge px-2 py-0.5 rounded-full"
                                  :class="dnsBadge(d.dmarc_status)" x-text="d.dmarc_status || 'unchecked'"></span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span class="inline-flex items-center gap-1 badge px-2 py-0.5 rounded-full"
                                  :class="dnsBadge(d.mx_status)" x-text="d.mx_status || 'unchecked'"></span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span class="text-sm font-bold" :class="(d.domain_health_score||0) >= 80 ? 'text-emerald-400' : (d.domain_health_score||0) >= 50 ? 'text-amber-400' : 'text-red-400'" x-text="d.domain_health_score ?? '—'"></span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span class="text-xs text-zinc-500" x-text="d.dns_last_checked_at ? timeAgo(d.dns_last_checked_at) : 'Never'"></span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <button @click="checkOne(d)" :disabled="d._checking" class="text-brand-400 hover:text-brand-300 transition">
                                <i data-lucide="refresh-cw" class="w-4 h-4" :class="d._checking && 'animate-spin'"></i>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="domains.length === 0" class="text-center py-12">
            <i data-lucide="globe" class="w-7 h-7 text-zinc-700 mx-auto mb-2"></i>
            <p class="text-zinc-500 text-sm">No domains found</p>
        </div>
    </div>

    <!-- Blacklist Results -->
    <div class="glass rounded-2xl overflow-hidden">
        <div class="px-5 py-3 border-b border-white/5 flex items-center gap-2">
            <i data-lucide="shield-alert" class="w-4 h-4 text-amber-400"></i>
            <h3 class="text-white font-semibold text-sm">Blacklist Monitor</h3>
        </div>

        <div x-show="blacklistResults.length > 0" class="divide-y divide-white/[0.03]">
            <template x-for="(bl, idx) in blacklistResults" :key="idx">
                <div class="px-5 py-4">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <i data-lucide="globe" class="w-4 h-4 text-zinc-500"></i>
                            <span class="text-white font-medium text-sm" x-text="bl.domain"></span>
                            <span class="text-zinc-600 text-xs" x-text="bl.ip ? '(' + bl.ip + ')' : ''"></span>
                        </div>
                        <span class="badge px-2 py-0.5 rounded-full"
                              :class="bl.listed_count > 0 ? 'bg-red-500/15 text-red-400' : 'bg-emerald-500/15 text-emerald-400'"
                              x-text="bl.listed_count > 0 ? bl.listed_count + ' listed' : 'All clear'"></span>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <template x-for="(result, name) in bl.results" :key="name">
                            <div class="flex items-center gap-2 p-2 rounded-lg bg-white/[0.02]">
                                <div class="w-5 h-5 rounded-full flex items-center justify-center" :class="result === 'clean' ? 'bg-emerald-500/20' : 'bg-red-500/20'">
                                    <i :data-lucide="result === 'clean' ? 'check' : 'x'" class="w-3 h-3" :class="result === 'clean' ? 'text-emerald-400' : 'text-red-400'"></i>
                                </div>
                                <span class="text-xs text-zinc-400" x-text="name"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <div x-show="blacklistResults.length === 0" class="text-center py-12">
            <i data-lucide="shield-check" class="w-7 h-7 text-zinc-700 mx-auto mb-2"></i>
            <p class="text-zinc-500 text-sm">Click "Blacklist Scan" to check your domains</p>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function dnsHealthPage() {
    return {
        domains: [],
        checkingAll: false, checkingBl: false,
        blacklistResults: [], blacklistHits: 0,
        summary: { healthy: 0, warnings: 0, failing: 0 },

        async init() {
            await this.load();
            this.$nextTick(() => lucide.createIcons());
        },

        async load() {
            try {
                const data = await apiCall('/api/warmup/dns-health');
                this.domains = (data.domains || data || []).map(d => ({ ...d, _checking: false }));
                this.computeSummary();
            } catch(e) { this.domains = []; }
            this.$nextTick(() => lucide.createIcons());
        },

        computeSummary() {
            this.summary = {
                healthy: this.domains.filter(d => (d.domain_health_score || 0) >= 80).length,
                warnings: this.domains.filter(d => (d.domain_health_score || 0) >= 50 && (d.domain_health_score || 0) < 80).length,
                failing: this.domains.filter(d => (d.domain_health_score || 0) > 0 && (d.domain_health_score || 0) < 50).length,
            };
        },

        dnsBadge(status) {
            const map = {
                valid: 'bg-emerald-500/15 text-emerald-400',
                pass: 'bg-emerald-500/15 text-emerald-400',
                weak: 'bg-amber-500/15 text-amber-400',
                missing: 'bg-red-500/15 text-red-400',
                fail: 'bg-red-500/15 text-red-400',
                invalid: 'bg-red-500/15 text-red-400',
                none: 'bg-zinc-500/15 text-zinc-500',
            };
            return map[status] || 'bg-zinc-500/15 text-zinc-500';
        },

        async checkOne(domain) {
            domain._checking = true;
            try {
                const res = await apiCall(`/api/warmup/dns-health/${domain.id}/check`, 'POST');
                Object.assign(domain, res.domain || res, { _checking: false });
                this.computeSummary();
                showToast(`DNS check complete for ${domain.domain_name}`, 'success');
            } catch(e) {
                domain._checking = false;
                showToast('DNS check failed: ' + e.message, 'error');
            }
            this.$nextTick(() => lucide.createIcons());
        },

        async checkAllDns() {
            this.checkingAll = true;
            try {
                const res = await apiCall('/api/warmup/dns-health/check-all', 'POST');
                await this.load();
                showToast('All DNS checks complete', 'success');
            } catch(e) { showToast('Batch check failed: ' + e.message, 'error'); }
            this.checkingAll = false;
        },

        async checkAllBlacklists() {
            this.checkingBl = true;
            this.blacklistResults = [];
            try {
                const res = await apiCall('/api/warmup/blacklist/check-all', 'POST');
                this.blacklistResults = res.results || [];
                this.blacklistHits = this.blacklistResults.reduce((sum, r) => sum + (r.listed_count || 0), 0);
                showToast('Blacklist scan complete', this.blacklistHits > 0 ? 'warning' : 'success');
            } catch(e) { showToast('Blacklist scan failed: ' + e.message, 'error'); }
            this.checkingBl = false;
            this.$nextTick(() => lucide.createIcons());
        },

        timeAgo(dt) {
            if (!dt) return 'Never';
            const diff = (Date.now() - new Date(dt).getTime()) / 1000;
            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        }
    };
}
</script>
@endpush
