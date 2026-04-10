@extends('layouts.app')
@section('title', 'Domains')
@section('page-title', 'Domains')
@section('page-description', 'Domain DNS health and sending capacity')

@section('content')
<div x-data="domainsPage()" x-init="init()">

    <div class="flex items-center justify-between mb-6">
        <span class="text-zinc-500 text-sm" x-text="domains.length + ' domains'"></span>
        <button @click="showModal = true; resetForm()" class="btn-primary px-4 py-2.5 rounded-xl text-white text-sm font-medium flex items-center gap-2">
            <i data-lucide="plus" class="w-4 h-4"></i> Add Domain
        </button>
    </div>

    <!-- Domain Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <template x-for="d in domains" :key="d.id">
            <div class="glass rounded-2xl p-5 hover:border-white/10 transition">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl gradient-info flex items-center justify-center">
                            <i data-lucide="globe" class="w-5 h-5 text-white"></i>
                        </div>
                        <div>
                            <p class="text-white font-semibold text-sm" x-text="d.domain_name"></p>
                            <p class="text-zinc-500 text-xs" x-text="(d.sender_mailboxes?.length || 0) + ' senders'"></p>
                        </div>
                    </div>
                    <button @click="deleteDomain(d.id)" class="btn-ghost p-2 rounded-lg text-zinc-600 hover:text-red-400">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>

                <!-- Health Score -->
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-zinc-500 text-xs">Health Score</span>
                        <span class="text-sm font-bold" :class="d.health_score >= 80 ? 'text-emerald-400' : d.health_score >= 50 ? 'text-amber-400' : 'text-red-400'" x-text="d.health_score + '/100'"></span>
                    </div>
                    <div class="w-full h-2 rounded-full bg-white/10 overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-700" :class="d.health_score >= 80 ? 'bg-emerald-500' : d.health_score >= 50 ? 'bg-amber-500' : 'bg-red-500'" :style="'width:' + d.health_score + '%'"></div>
                    </div>
                </div>

                <!-- DNS Checks -->
                <div class="grid grid-cols-4 gap-2 mb-4">
                    <template x-for="check in ['spf','dkim','dmarc','mx']" :key="check">
                        <div class="text-center p-2 rounded-lg bg-white/[0.03]">
                            <div class="w-5 h-5 mx-auto mb-1 rounded-full flex items-center justify-center" :class="getDnsStatus(d, check) === 'valid' ? 'bg-emerald-500/20' : getDnsStatus(d, check) === 'weak' ? 'bg-amber-500/20' : 'bg-red-500/20'">
                                <i :data-lucide="getDnsStatus(d, check) === 'valid' ? 'check' : getDnsStatus(d, check) === 'weak' ? 'alert-triangle' : 'x'" class="w-3 h-3" :class="getDnsStatus(d, check) === 'valid' ? 'text-emerald-400' : getDnsStatus(d, check) === 'weak' ? 'text-amber-400' : 'text-red-400'"></i>
                            </div>
                            <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500" x-text="check"></span>
                        </div>
                    </template>
                </div>

                <button @click="checkDns(d.id)" class="w-full py-2 rounded-xl text-xs font-medium text-brand-400 bg-brand-500/10 hover:bg-brand-500/20 transition flex items-center justify-center gap-1.5" :disabled="d._checking">
                    <i data-lucide="refresh-cw" class="w-3 h-3" :class="d._checking && 'animate-spin'"></i>
                    <span x-text="d._checking ? 'Checking...' : 'Run DNS Check'"></span>
                </button>
            </div>
        </template>
    </div>

    <div x-show="domains.length === 0" class="text-center py-20">
        <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-4">
            <i data-lucide="globe" class="w-7 h-7 text-zinc-600"></i>
        </div>
        <p class="text-zinc-400 font-medium">No domains registered</p>
        <p class="text-zinc-600 text-sm mt-1">Add your sending domains to monitor DNS health</p>
    </div>

    <!-- Add Domain Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showModal = false">
        <div class="w-full max-w-md glass rounded-2xl p-6 fade-in" @click.stop>
            <h3 class="text-white font-semibold text-lg mb-6">Add Domain</h3>
            <form @submit.prevent="saveDomain()" class="space-y-4">
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Domain Name *</label>
                    <input type="text" x-model="form.domain_name" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="yourdomain.com" required>
                </div>
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Daily Sending Cap</label>
                    <input type="number" x-model="form.daily_sending_cap" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="50">
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium">Add Domain</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function domainsPage() {
    return {
        domains: [], showModal: false, form: {},
        async init() { await this.load(); this.$nextTick(() => lucide.createIcons()); },
        async load() { try { this.domains = await apiCall('/api/warmup/domains'); } catch(e) { this.domains = []; } this.$nextTick(() => lucide.createIcons()); },
        resetForm() { this.form = { domain_name: '', daily_sending_cap: 50 }; },
        getDnsStatus(d, check) { return d.dns_check_results?.[check]?.status || 'missing'; },
        async saveDomain() {
            try { await apiCall('/api/warmup/domains', 'POST', this.form); showToast('Domain added'); this.showModal = false; await this.load(); }
            catch(e) { showToast('Error: ' + e.message, 'error'); }
        },
        async checkDns(id) {
            const d = this.domains.find(x => x.id === id);
            if (d) d._checking = true;
            try { await apiCall(`/api/warmup/domains/${id}/check-dns`, 'POST'); showToast('DNS check complete'); await this.load(); }
            catch(e) { showToast('DNS check failed', 'error'); }
            if (d) d._checking = false;
        },
        async deleteDomain(id) { if (!confirm('Delete this domain?')) return; try { await apiCall(`/api/warmup/domains/${id}`, 'DELETE'); showToast('Deleted'); await this.load(); } catch(e) { showToast('Error', 'error'); } }
    };
}
</script>
@endpush
