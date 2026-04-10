@extends('layouts.app')
@section('title', 'Warmup Profiles')
@section('page-title', 'Warmup Profiles')
@section('page-description', 'Define warmup stages, daily rules, and ramp-up patterns')

@section('content')
<div x-data="profilesPage()" x-init="init()">

    <div class="flex items-center justify-between mb-6">
        <span class="text-zinc-500 text-sm" x-text="profiles.length + ' profiles'"></span>
        <button @click="showModal = true; resetForm()" class="btn-primary px-4 py-2.5 rounded-xl text-white text-sm font-medium flex items-center gap-2">
            <i data-lucide="plus" class="w-4 h-4"></i> New Profile
        </button>
    </div>

    <!-- Profile Cards -->
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
        <template x-for="p in profiles" :key="p.id">
            <div class="glass rounded-2xl p-5 hover:border-white/10 transition">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl gradient-brand flex items-center justify-center">
                            <i data-lucide="settings" class="w-5 h-5 text-white"></i>
                        </div>
                        <div>
                            <p class="text-white font-semibold text-sm" x-text="p.profile_name"></p>
                            <p class="text-zinc-500 text-xs" x-text="p.total_days + '-day warmup'"></p>
                        </div>
                    </div>
                    <div class="flex gap-1">
                        <button @click="editProfile(p)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-brand-400" title="Edit"><i data-lucide="pencil" class="w-4 h-4"></i></button>
                        <button @click="viewProfile(p)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-brand-400"><i data-lucide="eye" class="w-4 h-4"></i></button>
                        <button @click="deleteProfile(p.id)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-red-400"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                    </div>
                </div>

                <!-- Stage Breakdown -->
                <div class="space-y-2 mb-4">
                    <template x-for="stage in ['ramp_up', 'plateau', 'maintenance']" :key="stage">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-semibold uppercase tracking-wider w-20"
                                  :class="stage === 'ramp_up' ? 'text-brand-400' : stage === 'plateau' ? 'text-emerald-400' : 'text-amber-400'"
                                  x-text="stage.replace('_',' ')"></span>
                            <div class="flex-1 h-1.5 rounded-full bg-white/10 overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500"
                                     :class="stage === 'ramp_up' ? 'bg-brand-400' : stage === 'plateau' ? 'bg-emerald-400' : 'bg-amber-400'"
                                     :style="'width:' + getStagePercent(p, stage) + '%'"></div>
                            </div>
                            <span class="text-zinc-500 text-[10px] w-14 text-right" x-text="getStageDays(p, stage) + ' days'"></span>
                        </div>
                    </template>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div class="text-center p-2 rounded-lg bg-white/[0.03]">
                        <p class="text-sm font-bold text-white" x-text="p.total_days"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Days</p>
                    </div>
                    <div class="text-center p-2 rounded-lg bg-white/[0.03]">
                        <p class="text-sm font-bold text-white" x-text="(p.daily_rules?.length || 0)"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Rules</p>
                    </div>
                    <div class="text-center p-2 rounded-lg bg-white/[0.03]">
                        <p class="text-sm font-bold text-white" x-text="(p.warmup_campaigns?.length || 0)"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Campaigns</p>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div x-show="profiles.length === 0" class="text-center py-20">
        <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-4">
            <i data-lucide="settings" class="w-7 h-7 text-zinc-600"></i>
        </div>
        <p class="text-zinc-400 font-medium">No warmup profiles</p>
        <p class="text-zinc-600 text-sm mt-1">Run the database seeder to generate default profiles</p>
    </div>

    <!-- Detail View Modal -->
    <div x-show="showDetail" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showDetail = false">
        <div class="w-full max-w-2xl max-h-[80vh] overflow-y-auto glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-white font-semibold text-lg" x-text="detailProfile?.profile_name + ' — Daily Rules'"></h3>
                <button @click="showDetail = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-3 py-2 text-[10px] font-semibold uppercase text-zinc-500">Day</th>
                            <th class="text-left px-3 py-2 text-[10px] font-semibold uppercase text-zinc-500">Stage</th>
                            <th class="text-left px-3 py-2 text-[10px] font-semibold uppercase text-zinc-500">New Threads</th>
                            <th class="text-left px-3 py-2 text-[10px] font-semibold uppercase text-zinc-500">Replies</th>
                            <th class="text-left px-3 py-2 text-[10px] font-semibold uppercase text-zinc-500">Opens</th>
                            <th class="text-left px-3 py-2 text-[10px] font-semibold uppercase text-zinc-500">Reply %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="r in detailProfile?.daily_rules || []" :key="r.day_number">
                            <tr class="border-b border-white/[0.03]">
                                <td class="px-3 py-2 font-medium text-white" x-text="'Day ' + r.day_number"></td>
                                <td class="px-3 py-2">
                                    <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                          :class="r.stage === 'ramp_up' ? 'bg-brand-500/15 text-brand-400' : r.stage === 'plateau' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'"
                                          x-text="r.stage?.replace('_',' ')"></span>
                                </td>
                                <td class="px-3 py-2 text-zinc-400" x-text="r.new_threads_count"></td>
                                <td class="px-3 py-2 text-zinc-400" x-text="r.reply_count"></td>
                                <td class="px-3 py-2 text-zinc-400" x-text="r.expected_opens"></td>
                                <td class="px-3 py-2 text-zinc-400" x-text="r.reply_chance_percent + '%'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- New Profile Modal with Visual Day-Rules Builder -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showModal = false">
        <div class="w-full max-w-4xl max-h-[90vh] overflow-y-auto glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-white font-semibold text-lg" x-text="editMode ? 'Edit Profile' : 'New Warmup Profile'"></h3>
                <button @click="showModal = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>

            <form @submit.prevent="saveProfile()" class="space-y-6">
                <!-- Basic Info -->
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Profile Name *</label>
                        <input type="text" x-model="form.profile_name" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" required>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Total Days *</label>
                        <input type="number" x-model.number="form.total_days" min="1" max="90" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" required @change="syncDayRules()">
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Preset</label>
                        <select @change="applyPreset($event.target.value)" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                            <option value="">Custom</option>
                            <option value="conservative">Conservative (14d)</option>
                            <option value="default">Default (21d)</option>
                            <option value="aggressive">Aggressive (10d)</option>
                        </select>
                    </div>
                </div>

                <!-- Visual Day-Rules Builder -->
                <div>
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-white font-semibold text-sm">Day Rules Builder</h4>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-brand-400">Ramp Up</span>
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-emerald-400">Plateau</span>
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-amber-400">Maintenance</span>
                        </div>
                    </div>

                    <!-- Mini chart preview -->
                    <div class="h-20 flex items-end gap-px mb-4 rounded-lg bg-white/[0.02] p-2">
                        <template x-for="(rule, idx) in dayRules" :key="idx">
                            <div class="flex-1 rounded-t transition-all duration-200"
                                 :class="rule.stage === 'ramp_up' ? 'bg-brand-400/60' : rule.stage === 'plateau' ? 'bg-emerald-400/60' : 'bg-amber-400/60'"
                                 :style="'height:' + Math.max(8, (rule.max_total / maxTotal * 100)) + '%'"
                                 :title="'Day ' + (idx+1) + ': ' + rule.max_total + ' total'"></div>
                        </template>
                    </div>

                    <!-- Day rules table -->
                    <div class="overflow-x-auto max-h-[40vh] overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 z-10" style="background: rgba(30,30,46,0.95);">
                                <tr class="border-b border-white/10">
                                    <th class="text-left px-2 py-2 text-[10px] font-semibold uppercase text-zinc-500 w-16">Day</th>
                                    <th class="text-left px-2 py-2 text-[10px] font-semibold uppercase text-zinc-500 w-32">Stage</th>
                                    <th class="text-center px-2 py-2 text-[10px] font-semibold uppercase text-zinc-500">New Threads</th>
                                    <th class="text-center px-2 py-2 text-[10px] font-semibold uppercase text-zinc-500">Replies</th>
                                    <th class="text-center px-2 py-2 text-[10px] font-semibold uppercase text-zinc-500">Max Total</th>
                                    <th class="text-center px-2 py-2 text-[10px] font-semibold uppercase text-zinc-500">Reply %</th>
                                    <th class="text-center px-2 py-2 text-[10px] font-semibold uppercase text-zinc-500">Opens</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(rule, idx) in dayRules" :key="idx">
                                    <tr class="border-b border-white/[0.03] hover:bg-white/[0.02]">
                                        <td class="px-2 py-1.5">
                                            <span class="text-white font-medium text-xs" x-text="'Day ' + (idx + 1)"></span>
                                        </td>
                                        <td class="px-2 py-1.5">
                                            <select x-model="rule.stage" class="bg-transparent border border-white/10 rounded-lg px-2 py-1 text-xs w-full"
                                                    :class="rule.stage === 'ramp_up' ? 'text-brand-400' : rule.stage === 'plateau' ? 'text-emerald-400' : 'text-amber-400'">
                                                <option value="ramp_up" class="bg-surface-900">Ramp Up</option>
                                                <option value="plateau" class="bg-surface-900">Plateau</option>
                                                <option value="maintenance" class="bg-surface-900">Maintenance</option>
                                            </select>
                                        </td>
                                        <td class="px-2 py-1.5 text-center">
                                            <input type="number" x-model.number="rule.new_threads" min="0" max="50"
                                                   class="bg-transparent border border-white/10 rounded-lg px-2 py-1 text-xs text-white text-center w-16">
                                        </td>
                                        <td class="px-2 py-1.5 text-center">
                                            <input type="number" x-model.number="rule.replies" min="0" max="50"
                                                   class="bg-transparent border border-white/10 rounded-lg px-2 py-1 text-xs text-white text-center w-16">
                                        </td>
                                        <td class="px-2 py-1.5 text-center">
                                            <input type="number" x-model.number="rule.max_total" min="0" max="100"
                                                   class="bg-transparent border border-white/10 rounded-lg px-2 py-1 text-xs text-white text-center w-16">
                                        </td>
                                        <td class="px-2 py-1.5 text-center">
                                            <input type="number" x-model.number="rule.reply_chance" min="0" max="100"
                                                   class="bg-transparent border border-white/10 rounded-lg px-2 py-1 text-xs text-white text-center w-16">
                                        </td>
                                        <td class="px-2 py-1.5 text-center">
                                            <input type="number" x-model.number="rule.expected_opens" min="0" max="50"
                                                   class="bg-transparent border border-white/10 rounded-lg px-2 py-1 text-xs text-white text-center w-16">
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium" :disabled="saving">
                        <span x-show="!saving" x-text="editMode ? 'Update Profile' : 'Create Profile'"></span>
                        <span x-show="saving">Saving...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function profilesPage() {
    return {
        profiles: [], showModal: false, showDetail: false, detailProfile: null,
        editMode: false, editId: null, saving: false,
        form: {}, dayRules: [],

        get maxTotal() {
            return Math.max(1, ...this.dayRules.map(r => r.max_total || 1));
        },

        async init() { await this.load(); this.$nextTick(() => lucide.createIcons()); },
        async load() { try { this.profiles = await apiCall('/api/warmup/profiles'); } catch(e) { this.profiles = []; } this.$nextTick(() => lucide.createIcons()); },

        resetForm() {
            this.form = { profile_name: '', total_days: 14 };
            this.editMode = false;
            this.editId = null;
            this.dayRules = [];
            this.syncDayRules();
        },

        syncDayRules() {
            const count = Math.max(1, Math.min(90, this.form.total_days || 14));
            while (this.dayRules.length < count) {
                const d = this.dayRules.length + 1;
                const stage = d <= Math.ceil(count * 0.4) ? 'ramp_up' : d <= Math.ceil(count * 0.75) ? 'plateau' : 'maintenance';
                const base = stage === 'ramp_up' ? Math.ceil(d * 0.7) : stage === 'plateau' ? Math.ceil(count * 0.3) : Math.ceil(count * 0.2);
                this.dayRules.push({
                    stage,
                    new_threads: Math.min(base, 15),
                    replies: Math.min(Math.ceil(base * 0.6), 10),
                    max_total: Math.min(base + Math.ceil(base * 0.6), 25),
                    reply_chance: stage === 'ramp_up' ? 20 : stage === 'plateau' ? 35 : 30,
                    expected_opens: Math.min(Math.ceil(base * 0.8), 20)
                });
            }
            if (this.dayRules.length > count) this.dayRules.splice(count);
        },

        applyPreset(preset) {
            if (preset === 'conservative') {
                this.form.total_days = 14;
                this.dayRules = Array.from({length: 14}, (_, i) => {
                    const d = i + 1;
                    if (d <= 5) return { stage: 'ramp_up', new_threads: d, replies: Math.ceil(d * 0.5), max_total: d + Math.ceil(d * 0.5), reply_chance: 15 + d, expected_opens: d };
                    if (d <= 10) return { stage: 'plateau', new_threads: 5, replies: 3, max_total: 8, reply_chance: 30, expected_opens: 6 };
                    return { stage: 'maintenance', new_threads: 3, replies: 2, max_total: 5, reply_chance: 25, expected_opens: 4 };
                });
            } else if (preset === 'default') {
                this.form.total_days = 21;
                this.dayRules = Array.from({length: 21}, (_, i) => {
                    const d = i + 1;
                    if (d <= 8) return { stage: 'ramp_up', new_threads: Math.ceil(d * 0.8), replies: Math.ceil(d * 0.4), max_total: Math.ceil(d * 1.2), reply_chance: 15 + d * 2, expected_opens: Math.ceil(d * 0.7) };
                    if (d <= 16) return { stage: 'plateau', new_threads: 7, replies: 4, max_total: 11, reply_chance: 35, expected_opens: 8 };
                    return { stage: 'maintenance', new_threads: 4, replies: 3, max_total: 7, reply_chance: 30, expected_opens: 5 };
                });
            } else if (preset === 'aggressive') {
                this.form.total_days = 10;
                this.dayRules = Array.from({length: 10}, (_, i) => {
                    const d = i + 1;
                    if (d <= 3) return { stage: 'ramp_up', new_threads: d * 2, replies: d, max_total: d * 3, reply_chance: 20 + d * 3, expected_opens: d * 2 };
                    if (d <= 7) return { stage: 'plateau', new_threads: 8, replies: 5, max_total: 13, reply_chance: 40, expected_opens: 10 };
                    return { stage: 'maintenance', new_threads: 5, replies: 3, max_total: 8, reply_chance: 30, expected_opens: 6 };
                });
            }
        },

        editProfile(p) {
            this.editMode = true;
            this.editId = p.id;
            this.form = { profile_name: p.profile_name, total_days: p.total_days || 14 };
            // Populate from day_rules JSON
            const dr = p.day_rules || {};
            this.dayRules = [];
            for (let d = 1; d <= (p.total_days || 14); d++) {
                const rule = dr[d] || {};
                this.dayRules.push({
                    stage: rule.stage || (d <= Math.ceil(p.total_days * 0.4) ? 'ramp_up' : d <= Math.ceil(p.total_days * 0.75) ? 'plateau' : 'maintenance'),
                    new_threads: rule.max_new_threads ?? 3,
                    replies: rule.max_replies ?? 2,
                    max_total: rule.max_total ?? 5,
                    reply_chance: rule.reply_chance_percent ?? 25,
                    expected_opens: rule.expected_opens ?? 3
                });
            }
            this.showModal = true;
        },

        getStagePercent(p, stage) { const days = this.getStageDays(p, stage); return p.total_days ? Math.round((days / p.total_days) * 100) : 0; },
        getStageDays(p, stage) {
            const dr = p.day_rules || {};
            return Object.values(dr).filter(r => r.stage === stage).length;
        },

        viewProfile(p) { this.detailProfile = p; this.showDetail = true; this.$nextTick(() => lucide.createIcons()); },

        async saveProfile() {
            this.saving = true;
            try {
                // Convert dayRules array to day_rules object keyed by day number
                const dayRulesObj = {};
                this.dayRules.forEach((r, i) => {
                    dayRulesObj[i + 1] = {
                        stage: r.stage,
                        max_new_threads: r.new_threads,
                        max_replies: r.replies,
                        max_total: r.max_total,
                        reply_chance_percent: r.reply_chance,
                        expected_opens: r.expected_opens,
                    };
                });

                const payload = {
                    profile_name: this.form.profile_name,
                    day_rules: dayRulesObj,
                    default_max_new_threads_per_day: Math.max(...this.dayRules.map(r => r.new_threads)),
                    default_max_reply_actions_per_day: Math.max(...this.dayRules.map(r => r.replies)),
                    default_max_total_actions_per_day: Math.max(...this.dayRules.map(r => r.max_total)),
                };

                if (this.editMode) {
                    await apiCall(`/api/warmup/profiles/${this.editId}`, 'PUT', payload);
                    showToast('Profile updated');
                } else {
                    await apiCall('/api/warmup/profiles', 'POST', payload);
                    showToast('Profile created');
                }
                this.showModal = false;
                await this.load();
            } catch(e) { showToast('Error: ' + e.message, 'error'); }
            this.saving = false;
        },

        async deleteProfile(id) { if (!confirm('Delete this profile?')) return; try { await apiCall(`/api/warmup/profiles/${id}`, 'DELETE'); showToast('Deleted'); await this.load(); } catch(e) { showToast('Error', 'error'); } }
    };
}
</script>
@endpush
