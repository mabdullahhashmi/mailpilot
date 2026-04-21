@extends('layouts.app')
@section('title', 'Warmup Campaigns')
@section('page-title', 'Warmup Campaigns')
@section('page-description', 'Campaign lifecycle management and progress tracking')

@section('content')
<div x-data="campaignsPage()" x-init="init()">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div class="flex items-center gap-4">
            <span class="text-zinc-500 text-sm" x-text="campaigns.length + ' campaigns'"></span>
            <template x-if="selectedCampaignIds.length > 0">
                <button @click="deleteSelectedCampaigns()" class="px-3 py-1.5 rounded-lg text-xs font-medium text-red-400 bg-red-500/10 hover:bg-red-500/20 border border-red-500/30 transition flex items-center gap-1.5">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Delete Selected (<span x-text="selectedCampaignIds.length"></span>)
                </button>
            </template>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button @click="selectAllCampaigns()" class="px-3 py-2.5 rounded-xl text-xs font-medium text-zinc-400 bg-white/5 hover:bg-white/10 transition">
                <span x-text="selectedCampaignIds.length === campaigns.length ? 'Deselect All' : 'Select All'"></span>
            </button>
            <button @click="openBulkCreateModal()" class="px-4 py-2.5 rounded-xl text-sm font-medium flex items-center gap-2 text-cyan-300 bg-cyan-500/10 hover:bg-cyan-500/15 border border-cyan-500/30 transition">
                <i data-lucide="layers-3" class="w-4 h-4"></i> Bulk Campaigns
            </button>
            <button @click="openCreateModal()" class="btn-primary px-4 py-2.5 rounded-xl text-white text-sm font-medium flex items-center gap-2">
                <i data-lucide="plus" class="w-4 h-4"></i> New Campaign
            </button>
        </div>
    </div>

    <!-- Campaign Cards -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <template x-for="c in campaigns" :key="c.id">
            <div class="glass flex flex-col rounded-2xl p-5 hover:border-white/10 transition relative" :class="selectedCampaignIds.includes(c.id) ? 'border border-cyan-500/50 bg-cyan-500/[0.02]' : ''">
                <!-- Checkbox -->
                <div class="absolute top-4 right-4 z-10">
                    <input type="checkbox" :checked="selectedCampaignIds.includes(c.id)" @change="toggleCampaignSelect(c.id)" class="w-4 h-4 rounded border-zinc-600 bg-zinc-900 text-cyan-500 focus:ring-cyan-500 cursor-pointer">
                </div>
                <!-- Header -->
                <div class="flex items-center justify-between mb-4 pr-6">
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

                <!-- Failed Task Summary -->
                <div class="mb-4 p-3 rounded-xl border" :class="(c.failed_tasks_total || 0) > 0 ? 'border-red-500/30 bg-red-500/10' : 'border-emerald-500/25 bg-emerald-500/10'">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-[11px] font-semibold uppercase tracking-wider" :class="(c.failed_tasks_total || 0) > 0 ? 'text-red-300' : 'text-emerald-300'">Failed Tasks</p>
                        <p class="text-xs font-semibold" :class="(c.failed_tasks_total || 0) > 0 ? 'text-red-300' : 'text-emerald-300'" x-text="(c.failed_tasks_total || 0) + ' total'"></p>
                    </div>

                    <template x-if="(c.failed_tasks_total || 0) > 0 && c.latest_failed_task">
                        <div class="mt-2">
                            <p class="text-[11px] text-red-200/90">
                                <span class="font-medium" x-text="c.latest_failed_task.friendly_title || formatEventType(c.latest_failed_task.event_type)"></span>
                                <span class="text-red-300/70"> | </span>
                                <span x-text="formatRelativeTime(c.latest_failed_task.failed_at)"></span>
                            </p>
                            <p class="text-[10px] text-red-100/80 truncate mt-1" :title="c.latest_failed_task.failure_reason || ''" x-text="c.latest_failed_task.friendly_reason || 'No reason provided'"></p>
                        </div>
                    </template>

                    <template x-if="(c.failed_tasks_total || 0) === 0">
                        <p class="text-[11px] text-emerald-200/90 mt-2">No failed tasks recorded.</p>
                    </template>
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
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Time Window Start</label>
                        <input type="time" x-model="form.time_window_start" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" value="08:00">
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Time Window End</label>
                        <input type="time" x-model="form.time_window_end" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" value="22:00">
                    </div>
                </div>
                <div x-show="false">
                    <input type="hidden" x-model="form.timezone">
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium">Create Campaign</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Campaign Modal -->
    <div x-show="showBulkModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showBulkModal = false">
        <div class="w-full max-w-5xl max-h-[92vh] overflow-y-auto glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex items-center justify-between mb-5">
                <div>
                    <h3 class="text-white font-semibold text-lg">Bulk Campaign Creator</h3>
                    <p class="text-zinc-500 text-xs mt-1">Create campaigns for multiple senders in one action.</p>
                </div>
                <button @click="showBulkModal = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>

            <div x-show="bulkResult" class="mb-4 p-3 rounded-xl" :class="bulkResult?.skipped > 0 ? 'bg-amber-500/10 border border-amber-500/25' : 'bg-emerald-500/10 border border-emerald-500/25'">
                <p class="text-sm font-medium" :class="bulkResult?.skipped > 0 ? 'text-amber-400' : 'text-emerald-400'">
                    <span x-text="bulkResult?.imported || 0"></span> created,
                    <span x-text="bulkResult?.skipped || 0"></span> skipped
                </p>
                <template x-if="bulkResult?.errors?.length">
                    <div class="mt-2 max-h-28 overflow-y-auto space-y-1">
                        <template x-for="err in bulkResult.errors" :key="err">
                            <p class="text-red-400/90 text-[11px]" x-text="err"></p>
                        </template>
                    </div>
                </template>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <div class="lg:col-span-2">
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Campaign Name Prefix</label>
                    <input type="text" x-model="bulkForm.campaign_name_prefix" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="Example: Batch April">
                </div>
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Warmup Profile *</label>
                    <select x-model="bulkForm.warmup_profile_id" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                        <option value="">Select profile</option>
                        <template x-for="p in profileOptions" :key="p.id">
                            <option :value="p.id" x-text="p.profile_name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Skip Active Campaigns</label>
                    <label class="flex items-center gap-2 py-2.5 px-3 rounded-xl border border-white/10 bg-white/[0.02] cursor-pointer">
                        <input type="checkbox" x-model="bulkForm.skip_existing_active" class="rounded border-zinc-600 bg-zinc-900 text-cyan-500 focus:ring-cyan-500">
                        <span class="text-xs text-zinc-300">Enabled</span>
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4 max-w-md">
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Time Window Start</label>
                    <input type="time" x-model="bulkForm.time_window_start" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                </div>
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Time Window End</label>
                    <input type="time" x-model="bulkForm.time_window_end" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                </div>
            </div>

            <div class="mb-4 max-w-md" x-show="false">
                <input type="hidden" x-model="bulkForm.timezone">
            </div>

            <div class="flex items-center gap-2 mb-3">
                <button type="button" @click="selectAllEligibleSenders()" class="px-3 py-2 rounded-lg text-xs text-zinc-200 bg-white/5 hover:bg-white/10 border border-white/10">Select All Active</button>
                <button type="button" @click="clearBulkSelection()" class="px-3 py-2 rounded-lg text-xs text-zinc-300 bg-white/0 hover:bg-white/5 border border-white/10">Clear</button>
                <span class="ml-auto text-[11px] text-zinc-500" x-text="(bulkForm.sender_mailbox_ids?.length || 0) + ' sender(s) selected'"></span>
            </div>

            <div class="border border-white/10 rounded-xl overflow-hidden overflow-x-auto">
                <table class="w-full min-w-[760px]">
                    <thead>
                        <tr class="border-b border-white/10 bg-white/[0.02]">
                            <th class="text-left px-4 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Select</th>
                            <th class="text-left px-4 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Sender</th>
                            <th class="text-left px-4 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Mailbox Status</th>
                            <th class="text-left px-4 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Existing Campaign</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="s in senderOptions" :key="s.id">
                            <tr class="border-b border-white/5 hover:bg-white/[0.02]">
                                <td class="px-4 py-2">
                                    <input
                                        type="checkbox"
                                        :disabled="s.status !== 'active'"
                                        :checked="bulkForm.sender_mailbox_ids.includes(Number(s.id))"
                                        @change="toggleBulkSender(s.id)"
                                        class="rounded border-zinc-600 bg-zinc-900 text-cyan-500 focus:ring-cyan-500 disabled:opacity-40"
                                    >
                                </td>
                                <td class="px-4 py-2 text-sm text-white" x-text="s.email_address"></td>
                                <td class="px-4 py-2">
                                    <span class="text-[11px] px-2 py-0.5 rounded-full"
                                          :class="s.status === 'active' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-zinc-500/15 text-zinc-400'"
                                          x-text="s.status"></span>
                                </td>
                                <td class="px-4 py-2 text-xs text-zinc-400">
                                    <template x-if="runningCampaignBySender(s.id)">
                                        <span class="text-amber-400" x-text="runningCampaignBySender(s.id).status + ' · ' + (runningCampaignBySender(s.id).campaign_name || ('Campaign #' + runningCampaignBySender(s.id).id))"></span>
                                    </template>
                                    <template x-if="!runningCampaignBySender(s.id)">
                                        <span class="text-zinc-500">none</span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end gap-3 pt-4">
                <button type="button" @click="showBulkModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Cancel</button>
                <button type="button" @click="saveBulkCampaigns()" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium" :disabled="bulkSaving">
                    <span x-show="!bulkSaving">Create Bulk Campaigns</span>
                    <span x-show="bulkSaving">Creating...</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function campaignsPage() {
    return {
        campaigns: [],
        selectedCampaignIds: [],
        senderOptions: [],
        profileOptions: [],
        showModal: false,
        showBulkModal: false,
        bulkSaving: false,
        bulkResult: null,
        form: {},
        bulkForm: {},
        timezoneOptions: [
            { value: '', label: 'Use Sender Timezone (Default)' },
            { value: 'America/Chicago', label: 'US Central (Texas)' },
            { value: 'America/New_York', label: 'US Eastern' },
            { value: 'America/Los_Angeles', label: 'US Pacific' },
            { value: 'UTC', label: 'UTC' },
        ],
        async init() {
            this.resetForm();
            this.resetBulkForm();
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
        async openBulkCreateModal() {
            this.resetBulkForm();
            this.showBulkModal = true;
            await this.loadOptions();
            this.$nextTick(() => lucide.createIcons());
        },
        hasShortTestProfile() {
            return (this.profileOptions || []).some(p => (p.profile_name || '') === 'Short Test (6 Hour Days)');
        },
        resetForm() { this.form = { campaign_name: '', sender_mailbox_id: '', warmup_profile_id: '', time_window_start: '08:00', time_window_end: '22:00', timezone: Intl.DateTimeFormat().resolvedOptions().timeZone }; },
        resetBulkForm() {
            this.bulkForm = {
                campaign_name_prefix: '',
                warmup_profile_id: '',
                time_window_start: '08:00',
                time_window_end: '22:00',
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                skip_existing_active: true,
                sender_mailbox_ids: [],
            };
            this.bulkResult = null;
        },
        runningCampaignBySender(senderId) {
            return (this.campaigns || []).find(c => Number(c.sender_mailbox_id) === Number(senderId) && ['active', 'paused'].includes(c.status));
        },
        toggleBulkSender(senderId) {
            const id = Number(senderId);
            const next = new Set((this.bulkForm.sender_mailbox_ids || []).map(Number));
            if (next.has(id)) next.delete(id);
            else next.add(id);
            this.bulkForm.sender_mailbox_ids = Array.from(next.values());
        },
        clearBulkSelection() {
            this.bulkForm.sender_mailbox_ids = [];
        },
        selectAllEligibleSenders() {
            this.bulkForm.sender_mailbox_ids = (this.senderOptions || [])
                .filter(s => s.status === 'active')
                .map(s => Number(s.id));
        },
        dayPercent(c) { const total = c.profile?.total_days || 14; return Math.min(100, Math.round(((c.current_day_number || 1) / total) * 100)); },
        statusGradient(s) { return { active: 'gradient-success', paused: 'gradient-warning', draft: 'bg-zinc-700', stopped: 'bg-red-900/50', completed: 'gradient-brand' }[s] || 'bg-zinc-700'; },
        statusBadge(s) { return { active: 'bg-emerald-500/15 text-emerald-400', paused: 'bg-amber-500/15 text-amber-400', draft: 'bg-zinc-500/15 text-zinc-400', stopped: 'bg-red-500/15 text-red-400', completed: 'bg-brand-500/15 text-brand-400' }[s] || 'bg-zinc-500/15 text-zinc-400'; },
        formatStage(stage) { if (!stage) return '—'; return stage.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); },
        formatEventType(eventType) {
            if (!eventType) return 'Unknown task';
            return eventType.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        },
        formatRelativeTime(iso) {
            if (!iso) return 'Unknown time';
            const ts = new Date(iso);
            if (Number.isNaN(ts.getTime())) return 'Unknown time';
            const seconds = Math.floor((Date.now() - ts.getTime()) / 1000);
            if (seconds < 60) return 'just now';
            const minutes = Math.floor(seconds / 60);
            if (minutes < 60) return `${minutes}m ago`;
            const hours = Math.floor(minutes / 60);
            if (hours < 24) return `${hours}h ago`;
            const days = Math.floor(hours / 24);
            return `${days}d ago`;
        },
        async saveCampaign() {
            try { await apiCall('/api/warmup/campaigns', 'POST', this.form); showToast('Campaign created'); this.showModal = false; await this.init(); }
            catch(e) { showToast('Error: ' + e.message, 'error'); }
        },
        async saveBulkCampaigns() {
            if (!this.bulkForm.warmup_profile_id) {
                showToast('Select a warmup profile for bulk creation.', 'error');
                return;
            }

            const senderIds = (this.bulkForm.sender_mailbox_ids || []).map(Number).filter(Boolean);
            if (!senderIds.length) {
                showToast('Select at least one sender for bulk creation.', 'error');
                return;
            }

            this.bulkSaving = true;
            this.bulkResult = null;

            try {
                const payload = {
                    sender_mailbox_ids: senderIds,
                    warmup_profile_id: Number(this.bulkForm.warmup_profile_id),
                    campaign_name_prefix: (this.bulkForm.campaign_name_prefix || '').trim() || null,
                    time_window_start: this.bulkForm.time_window_start || null,
                    time_window_end: this.bulkForm.time_window_end || null,
                    timezone: this.bulkForm.timezone || null,
                    skip_existing_active: !!this.bulkForm.skip_existing_active,
                };

                const res = await apiCall('/api/warmup/campaigns/bulk', 'POST', payload);
                this.bulkResult = res;

                if ((res.imported || 0) > 0) {
                    await this.loadCampaigns();
                }

                if ((res.skipped || 0) > 0) {
                    showToast(`Bulk campaigns created: ${res.imported || 0}, skipped: ${res.skipped || 0}.`, 'info');
                } else {
                    showToast(`Bulk campaigns created: ${res.imported || 0}.`, 'success');
                }
            } catch (e) {
                showToast('Bulk creation failed: ' + e.message, 'error');
            } finally {
                this.bulkSaving = false;
            }
        },
        toggleCampaignSelect(id) {
            const index = this.selectedCampaignIds.indexOf(id);
            if (index === -1) {
                this.selectedCampaignIds.push(id);
            } else {
                this.selectedCampaignIds.splice(index, 1);
            }
        },
        selectAllCampaigns() {
            if (this.selectedCampaignIds.length === this.campaigns.length) {
                this.selectedCampaignIds = [];
            } else {
                this.selectedCampaignIds = this.campaigns.map(c => c.id);
            }
        },
        async deleteSelectedCampaigns() {
            if (!confirm(`Are you sure you want to delete ${this.selectedCampaignIds.length} campaign(s)? This action cannot be undone.`)) return;
            try {
                await apiCall('/api/warmup/campaigns/bulk-delete', 'POST', { campaign_ids: this.selectedCampaignIds });
                showToast(`${this.selectedCampaignIds.length} campaigns deleted`);
                this.selectedCampaignIds = [];
                await this.init();
            } catch (e) {
                showToast('Bulk delete failed: ' + e.message, 'error');
            }
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
