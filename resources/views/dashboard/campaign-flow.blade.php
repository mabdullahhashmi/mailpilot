@extends('layouts.app')
@section('title', 'Campaign Flow Test')
@section('page-title', 'Campaign Flow Test')
@section('page-description', 'Run phase-based sender-seed conversations with second-based delays (isolated from daily warmup quota)')

@section('content')
<div x-data="campaignFlowTest()" x-init="init()" class="space-y-6">
    <div class="glass rounded-2xl p-5 border border-emerald-500/20">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h3 class="text-white font-semibold text-base">Isolated Flow Tester</h3>
                <p class="text-zinc-400 text-sm mt-1">This module is for controlled testing and does not use the normal daily planner quota path.</p>
            </div>
            <span class="badge px-2.5 py-1 rounded-full bg-emerald-500/15 text-emerald-400">Test Mode</span>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div class="xl:col-span-1 glass rounded-2xl p-5">
            <h4 class="text-white font-semibold text-sm mb-4">New Flow Test</h4>

            <div x-show="metaLoading" class="text-zinc-500 text-sm">Loading senders and seeds...</div>

            <div x-show="!metaLoading" class="space-y-4">
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5">Sender</label>
                    <select x-model.number="form.sender_mailbox_id" class="w-full input-dark rounded-lg px-3 py-2 text-sm">
                        <option value="">Select sender</option>
                        <template x-for="s in senders" :key="s.id">
                            <option :value="s.id" x-text="s.email_address"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs text-zinc-400">Seeds (choose 1-3)</label>
                        <span class="text-xs" :class="form.seed_ids.length >= 1 && form.seed_ids.length <= 3 ? 'text-emerald-400' : 'text-amber-400'" x-text="form.seed_ids.length + ' selected'"></span>
                    </div>
                    <div class="max-h-44 overflow-y-auto space-y-2 pr-1">
                        <template x-for="seed in seeds" :key="seed.id">
                            <label class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg bg-white/[0.03] hover:bg-white/[0.06] cursor-pointer">
                                <input type="checkbox" :value="seed.id" :checked="form.seed_ids.includes(seed.id)" @change="toggleSeed(seed.id)" class="rounded border-zinc-600 bg-zinc-900 text-brand-500 focus:ring-brand-500">
                                <div class="min-w-0">
                                    <p class="text-sm text-zinc-200 truncate" x-text="seed.email_address"></p>
                                    <p class="text-[10px] text-zinc-500" x-text="(seed.provider_type || 'seed').toUpperCase()"></p>
                                </div>
                            </label>
                        </template>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5">Phases</label>
                        <select x-model.number="form.phase_count" class="w-full input-dark rounded-lg px-3 py-2 text-sm">
                            <template x-for="p in [1,2,3,4,5]" :key="p">
                                <option :value="p" x-text="p"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5">Open Delay (sec)</label>
                        <input x-model.number="form.open_delay_seconds" type="number" min="1" max="300" class="w-full input-dark rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5">Star Delay (sec)</label>
                        <input x-model.number="form.star_delay_seconds" type="number" min="0" max="120" class="w-full input-dark rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5">Reply Delay (sec)</label>
                        <input x-model.number="form.reply_delay_seconds" type="number" min="1" max="300" class="w-full input-dark rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>

                <button @click="startRun()" :disabled="submitting" class="w-full btn-primary rounded-lg px-4 py-2.5 text-white text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed">
                    <span x-show="!submitting">Start Flow Test</span>
                    <span x-show="submitting">Starting...</span>
                </button>

                <p class="text-[11px] text-zinc-500">Phase 1 = sender send -> seed open -> seed star -> seed reply. Higher phases add alternating open+reply turns.</p>
            </div>
        </div>

        <div class="xl:col-span-2 space-y-6">
            <div class="glass rounded-2xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-white font-semibold text-sm">Current Run</h4>
                    <button @click="refreshCurrent()" class="btn-ghost px-3 py-1.5 rounded-lg text-xs text-brand-400">Refresh</button>
                </div>

                <div x-show="!currentRun" class="text-zinc-500 text-sm py-8 text-center">No active run selected yet.</div>

                <div x-show="currentRun" class="space-y-4" x-cloak>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                        <div class="glass-light rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-white" x-text="currentRun.id"></p>
                            <p class="text-[10px] text-zinc-500 uppercase">Run ID</p>
                        </div>
                        <div class="glass-light rounded-lg p-3 text-center">
                            <p class="text-lg font-bold" :class="statusColor(currentRun.status)" x-text="(currentRun.status || '—').toUpperCase()"></p>
                            <p class="text-[10px] text-zinc-500 uppercase">Status</p>
                        </div>
                        <div class="glass-light rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-white" x-text="currentRun.steps_total ?? 0"></p>
                            <p class="text-[10px] text-zinc-500 uppercase">Total Steps</p>
                        </div>
                        <div class="glass-light rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-emerald-400" x-text="currentRun.steps_completed ?? 0"></p>
                            <p class="text-[10px] text-zinc-500 uppercase">Completed</p>
                        </div>
                        <div class="glass-light rounded-lg p-3 text-center">
                            <p class="text-lg font-bold text-red-400" x-text="(currentRun.steps_failed ?? 0) + (currentRun.steps_skipped ?? 0)"></p>
                            <p class="text-[10px] text-zinc-500 uppercase">Failed/Skipped</p>
                        </div>
                    </div>

                    <div class="glass rounded-xl overflow-hidden">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-white/5 text-zinc-500 text-[11px] uppercase tracking-wider">
                                    <th class="text-left px-4 py-3">Seed</th>
                                    <th class="text-left px-4 py-3">Step</th>
                                    <th class="text-left px-4 py-3">Action</th>
                                    <th class="text-left px-4 py-3">Scheduled</th>
                                    <th class="text-left px-4 py-3">Status</th>
                                    <th class="text-left px-4 py-3">Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="step in steps" :key="step.id">
                                    <tr class="border-b border-white/[0.03]">
                                        <td class="px-4 py-3 text-zinc-300" x-text="step.seed_mailbox?.email_address || '—'"></td>
                                        <td class="px-4 py-3 text-zinc-400" x-text="step.step_index"></td>
                                        <td class="px-4 py-3 text-zinc-200" x-text="formatAction(step.action_type)"></td>
                                        <td class="px-4 py-3 text-zinc-400" x-text="formatDate(step.scheduled_at)"></td>
                                        <td class="px-4 py-3">
                                            <span class="badge px-2 py-0.5 rounded-full" :class="statusBadge(step.status)" x-text="step.status"></span>
                                        </td>
                                        <td class="px-4 py-3 text-[11px] text-zinc-500">
                                            <span x-show="step.notes" x-text="step.notes"></span>
                                            <span x-show="!step.notes && step.error_message" class="text-red-400" x-text="step.error_message"></span>
                                            <span x-show="!step.notes && !step.error_message">—</span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="glass rounded-2xl p-5">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-white font-semibold text-sm">Recent Runs</h4>
                    <button @click="loadRuns()" class="btn-ghost px-3 py-1.5 rounded-lg text-xs text-brand-400">Reload</button>
                </div>

                <div class="space-y-2">
                    <template x-for="run in runs" :key="run.id">
                        <button @click="loadRun(run.id)" class="w-full text-left px-4 py-3 rounded-lg bg-white/[0.03] hover:bg-white/[0.06] transition">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-zinc-200 text-sm">Run #<span x-text="run.id"></span> · <span x-text="run.sender_mailbox?.email_address || 'Unknown sender'"></span></p>
                                    <p class="text-zinc-500 text-xs mt-0.5">
                                        Phases: <span x-text="run.phase_count"></span> · Steps: <span x-text="run.steps_total"></span> · Completed: <span x-text="run.steps_completed"></span>
                                    </p>
                                </div>
                                <span class="badge px-2 py-0.5 rounded-full" :class="statusBadge(run.status)" x-text="run.status"></span>
                            </div>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function campaignFlowTest() {
    return {
        metaLoading: true,
        submitting: false,
        senders: [],
        seeds: [],
        runs: [],
        currentRun: null,
        steps: [],
        pollTimer: null,
        form: {
            sender_mailbox_id: '',
            seed_ids: [],
            phase_count: 3,
            open_delay_seconds: 20,
            star_delay_seconds: 10,
            reply_delay_seconds: 20,
        },

        async init() {
            await this.loadMeta();
            await this.loadRuns();
        },

        async loadMeta() {
            this.metaLoading = true;
            try {
                const data = await apiCall('/api/warmup/flow-tests/meta');
                this.senders = data.senders || [];
                this.seeds = data.seeds || [];

                if (data.defaults) {
                    this.form.phase_count = data.defaults.phase_count ?? this.form.phase_count;
                    this.form.open_delay_seconds = data.defaults.open_delay_seconds ?? this.form.open_delay_seconds;
                    this.form.star_delay_seconds = data.defaults.star_delay_seconds ?? this.form.star_delay_seconds;
                    this.form.reply_delay_seconds = data.defaults.reply_delay_seconds ?? this.form.reply_delay_seconds;
                }

                if (!this.form.sender_mailbox_id && this.senders.length) {
                    this.form.sender_mailbox_id = this.senders[0].id;
                }

                if (this.form.seed_ids.length === 0 && this.seeds.length >= 1) {
                    this.form.seed_ids = [this.seeds[0].id];
                }
            } catch (e) {
                showToast('Failed to load flow tester data: ' + e.message, 'error');
            }
            this.metaLoading = false;
        },

        toggleSeed(seedId) {
            if (this.form.seed_ids.includes(seedId)) {
                this.form.seed_ids = this.form.seed_ids.filter(id => id !== seedId);
                return;
            }

            if (this.form.seed_ids.length >= 3) {
                showToast('You can select maximum 3 seeds for one test run.', 'warning');
                return;
            }

            this.form.seed_ids.push(seedId);
        },

        async startRun() {
            if (!this.form.sender_mailbox_id) {
                showToast('Please select a sender mailbox.', 'warning');
                return;
            }

            if (this.form.seed_ids.length < 1 || this.form.seed_ids.length > 3) {
                showToast('Please select 1 to 3 seeds.', 'warning');
                return;
            }

            this.submitting = true;
            try {
                const payload = {
                    sender_mailbox_id: Number(this.form.sender_mailbox_id),
                    seed_ids: this.form.seed_ids,
                    phase_count: Number(this.form.phase_count),
                    open_delay_seconds: Number(this.form.open_delay_seconds),
                    star_delay_seconds: Number(this.form.star_delay_seconds),
                    reply_delay_seconds: Number(this.form.reply_delay_seconds),
                };

                const result = await apiCall('/api/warmup/flow-tests/start', 'POST', payload);
                showToast(result.message || 'Flow test started.', 'success');

                await this.loadRuns();
                if (result.run_id) {
                    await this.loadRun(result.run_id);
                }
            } catch (e) {
                showToast('Flow test start failed: ' + e.message, 'error');
            }
            this.submitting = false;
        },

        async loadRuns() {
            try {
                const data = await apiCall('/api/warmup/flow-tests');
                this.runs = data.runs || [];
            } catch (e) {
                showToast('Failed to load runs: ' + e.message, 'error');
            }
        },

        async loadRun(runId) {
            try {
                const data = await apiCall(`/api/warmup/flow-tests/${runId}`);
                this.currentRun = data.run || null;
                this.steps = data.steps || [];
                this.updatePolling();
            } catch (e) {
                showToast('Failed to load run details: ' + e.message, 'error');
            }
        },

        async refreshCurrent() {
            if (!this.currentRun?.id) return;
            await this.loadRun(this.currentRun.id);
            await this.loadRuns();
        },

        updatePolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }

            if (!this.currentRun || !['queued', 'running'].includes(this.currentRun.status)) {
                return;
            }

            this.pollTimer = setInterval(async () => {
                await this.refreshCurrent();
            }, 5000);
        },

        formatAction(action) {
            return (action || '').replace(/_/g, ' ');
        },

        formatDate(dt) {
            if (!dt) return '—';
            return new Date(dt).toLocaleString();
        },

        statusColor(status) {
            return {
                queued: 'text-amber-400',
                running: 'text-blue-400',
                completed: 'text-emerald-400',
                failed: 'text-red-400',
            }[status] || 'text-zinc-400';
        },

        statusBadge(status) {
            return {
                pending: 'bg-amber-500/15 text-amber-400',
                queued: 'bg-amber-500/15 text-amber-400',
                executing: 'bg-blue-500/15 text-blue-400',
                running: 'bg-blue-500/15 text-blue-400',
                completed: 'bg-emerald-500/15 text-emerald-400',
                failed: 'bg-red-500/15 text-red-400',
                skipped: 'bg-zinc-500/15 text-zinc-400',
            }[status] || 'bg-zinc-500/15 text-zinc-400';
        },
    };
}
</script>
@endpush
