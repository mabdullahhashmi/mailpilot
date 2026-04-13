@extends('layouts.app')
@section('title', 'Warmup Campaigns')
@section('page-title', 'Warmup Campaigns')
@section('page-description', 'Campaign lifecycle management and progress tracking')

@section('content')
<div x-data="campaignsPage()" x-init="init()">

    <div class="flex items-center justify-between mb-6">
        <span class="text-zinc-500 text-sm" x-text="campaigns.length + ' campaigns'"></span>
        <button @click="openCreateModal()" class="btn-primary px-4 py-2.5 rounded-xl text-white text-sm font-medium flex items-center gap-2">
            <i data-lucide="plus" class="w-4 h-4"></i> New Campaign
        </button>
    </div>

    <!-- Campaign Cards -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <template x-for="c in campaigns" :key="c.id">
            <div class="glass rounded-2xl p-5 hover:border-white/10 transition">
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <a :href="'/campaigns/' + c.id" class="flex items-center gap-3 hover:opacity-80 transition">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center" :class="statusGradient(c.status)">
                            <i data-lucide="flame" class="w-5 h-5 text-white"></i>
                        </div>
                        <div>
                            <p class="text-white font-semibold text-sm" x-text="c.campaign_name"></p>
                            <p class="text-zinc-500 text-xs" x-text="'Day ' + (c.current_day_number || 1) + ' / ' + (c.profile?.total_days || '—')"></p>
                        </div>
                    </a>
                    <span class="badge px-2.5 py-1 rounded-full text-[10px] font-semibold uppercase tracking-widest"
                          :class="statusBadge(c.status)" x-text="c.status"></span>
                </div>

                <!-- Progress -->
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-zinc-500 text-xs">Progress</span>
                        <span class="text-xs font-bold text-white" x-text="dayPercent(c) + '%'"></span>
                    </div>
                    <div class="w-full h-2 rounded-full bg-white/10 overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-700 gradient-brand" :style="'width:' + dayPercent(c) + '%'"></div>
                    </div>
                </div>

                <!-- Stage Info -->
                <div class="grid grid-cols-3 gap-2 mb-4">
                    <div class="text-center p-2 rounded-lg bg-white/[0.03]">
                        <p class="text-sm font-bold text-white" x-text="formatStage(c.current_stage)"></p>
                        <p class="text-[10px] text-zinc-500 uppercase tracking-wider">Stage</p>
                    </div>
                    <div class="text-center p-2 rounded-lg bg-white/[0.03]">
                        <p class="text-sm font-bold text-white truncate" x-text="c.sender_mailbox?.email_address || '—'" :title="c.sender_mailbox?.email_address"></p>
                        <p class="text-[10px] text-zinc-500 uppercase tracking-wider">Sender</p>
                    </div>
                    <div class="text-center p-2 rounded-lg bg-white/[0.03]">
                        <p class="text-sm font-bold text-white" x-text="c.profile?.profile_name || '—'"></p>
                        <p class="text-[10px] text-zinc-500 uppercase tracking-wider">Profile</p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex gap-2">
                    <template x-if="c.status === 'draft' || c.status === 'stopped'">
                        <button @click="action(c.id, 'start')" class="flex-1 py-2 rounded-xl text-xs font-medium text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition flex items-center justify-center gap-1">
                            <i data-lucide="play" class="w-3 h-3"></i> Start
                        </button>
                    </template>
                    <template x-if="c.status === 'active'">
                        <button @click="action(c.id, 'pause')" class="flex-1 py-2 rounded-xl text-xs font-medium text-amber-400 bg-amber-500/10 hover:bg-amber-500/20 transition flex items-center justify-center gap-1">
                            <i data-lucide="pause" class="w-3 h-3"></i> Pause
                        </button>
                    </template>
                    <template x-if="c.status === 'paused'">
                        <button @click="action(c.id, 'resume')" class="flex-1 py-2 rounded-xl text-xs font-medium text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition flex items-center justify-center gap-1">
                            <i data-lucide="play" class="w-3 h-3"></i> Resume
                        </button>
                    </template>
                    <template x-if="c.status === 'active' || c.status === 'paused'">
                        <button @click="action(c.id, 'stop')" class="flex-1 py-2 rounded-xl text-xs font-medium text-red-400 bg-red-500/10 hover:bg-red-500/20 transition flex items-center justify-center gap-1">
                            <i data-lucide="square" class="w-3 h-3"></i> Stop
                        </button>
                    </template>
                    <template x-if="c.status === 'completed'">
                        <button @click="action(c.id, 'restart')" class="flex-1 py-2 rounded-xl text-xs font-medium text-brand-400 bg-brand-500/10 hover:bg-brand-500/20 transition flex items-center justify-center gap-1">
                            <i data-lucide="refresh-cw" class="w-3 h-3"></i> Restart
                        </button>
                    </template>
                    <button @click="deleteCampaign(c.id)" class="py-2 px-3 rounded-xl text-xs text-zinc-600 hover:text-red-400 bg-white/[0.03] hover:bg-red-500/10 transition">
                        <i data-lucide="trash-2" class="w-3 h-3"></i>
                    </button>
                </div>
            </div>
        </template>
    </div>

    <div x-show="campaigns.length === 0" class="text-center py-20">
        <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-4">
            <i data-lucide="flame" class="w-7 h-7 text-zinc-600"></i>
        </div>
        <p class="text-zinc-400 font-medium">No campaigns yet</p>
        <p class="text-zinc-600 text-sm mt-1">Create your first warmup campaign to start scheduling events</p>
    </div>

    <!-- New Campaign Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showModal = false">
        <div class="w-full max-w-lg glass rounded-2xl p-6 fade-in" @click.stop>
            <h3 class="text-white font-semibold text-lg mb-6">New Warmup Campaign</h3>
            <form @submit.prevent="saveCampaign()" class="space-y-4">
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Campaign Name *</label>
                    <input type="text" x-model="form.campaign_name" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" required>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Sender Mailbox *</label>
                        <select x-model="form.sender_mailbox_id" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" required>
                            <option value="">Select sender</option>
                            <template x-for="s in senderOptions" :key="s.id">
                                <option :value="s.id" x-text="s.email_address"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Warmup Profile *</label>
                        <select x-model="form.warmup_profile_id" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" required>
                            <option value="">Select profile</option>
                            <template x-for="p in profileOptions" :key="p.id">
                                <option :value="p.id" x-text="p.profile_name"></option>
                            </template>
                        </select>
                        <p x-show="!hasShortTestProfile()" class="mt-1.5 text-[11px] text-amber-400/90">
                            Short Test (6 Hour Days) profile not found yet. Run latest migrations + warmup seeder on server.
                        </p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Time Window Start</label>
                        <input type="time" x-model="form.time_window_start" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" value="08:00">
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Time Window End</label>
                        <input type="time" x-model="form.time_window_end" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" value="22:00">
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium">Create Campaign</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function campaignsPage() {
    return {
        campaigns: [], senderOptions: [], profileOptions: [], showModal: false, form: {},
        async init() {
            await this.loadCampaigns();
            await this.loadOptions();
            this.$nextTick(() => lucide.createIcons());
        },
        async loadCampaigns() {
            try {
                this.campaigns = await apiCall('/api/warmup/campaigns');
            } catch (e) {
                this.campaigns = [];
            }
        },
        async loadOptions() {
            const [senders, profiles] = await Promise.allSettled([
                apiCall('/api/warmup/sender-mailboxes'),
                apiCall('/api/warmup/profiles'),
            ]);
            this.senderOptions = senders.value ?? [];
            this.profileOptions = profiles.value ?? [];
        },
        async openCreateModal() {
            this.resetForm();
            this.showModal = true;
            await this.loadOptions();
            this.$nextTick(() => lucide.createIcons());
        },
        hasShortTestProfile() {
            return (this.profileOptions || []).some(p => (p.profile_name || '') === 'Short Test (6 Hour Days)');
        },
        resetForm() { this.form = { campaign_name: '', sender_mailbox_id: '', warmup_profile_id: '', time_window_start: '08:00', time_window_end: '22:00' }; },
        dayPercent(c) { const total = c.profile?.total_days || 14; return Math.min(100, Math.round(((c.current_day_number || 1) / total) * 100)); },
        statusGradient(s) { return { active: 'gradient-success', paused: 'gradient-warning', draft: 'bg-zinc-700', stopped: 'bg-red-900/50', completed: 'gradient-brand' }[s] || 'bg-zinc-700'; },
        statusBadge(s) { return { active: 'bg-emerald-500/15 text-emerald-400', paused: 'bg-amber-500/15 text-amber-400', draft: 'bg-zinc-500/15 text-zinc-400', stopped: 'bg-red-500/15 text-red-400', completed: 'bg-brand-500/15 text-brand-400' }[s] || 'bg-zinc-500/15 text-zinc-400'; },
        formatStage(stage) { if (!stage) return '—'; return stage.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); },
        async saveCampaign() {
            try { await apiCall('/api/warmup/campaigns', 'POST', this.form); showToast('Campaign created'); this.showModal = false; await this.init(); }
            catch(e) { showToast('Error: ' + e.message, 'error'); }
        },
        async action(id, act) {
            try { await apiCall(`/api/warmup/campaigns/${id}/${act}`, 'POST'); showToast(`Campaign ${act}ed`); await this.init(); }
            catch(e) { showToast('Error: ' + e.message, 'error'); }
        },
        async deleteCampaign(id) { if (!confirm('Delete this campaign?')) return; try { await apiCall(`/api/warmup/campaigns/${id}`, 'DELETE'); showToast('Deleted'); await this.init(); } catch(e) { showToast('Error', 'error'); } }
    };
}
</script>
@endpush
