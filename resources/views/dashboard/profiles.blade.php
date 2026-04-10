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

    <!-- New Profile Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showModal = false">
        <div class="w-full max-w-md glass rounded-2xl p-6 fade-in" @click.stop>
            <h3 class="text-white font-semibold text-lg mb-6">New Warmup Profile</h3>
            <form @submit.prevent="saveProfile()" class="space-y-4">
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Profile Name *</label>
                    <input type="text" x-model="form.profile_name" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" required>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Total Days *</label>
                        <input type="number" x-model="form.total_days" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" required>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Max Threads/Day</label>
                        <input type="number" x-model="form.max_threads_per_day" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" value="10">
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium">Create</button>
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
        profiles: [], showModal: false, showDetail: false, detailProfile: null, form: {},
        async init() { await this.load(); this.$nextTick(() => lucide.createIcons()); },
        async load() { try { this.profiles = await apiCall('/api/warmup/profiles'); } catch(e) { this.profiles = []; } this.$nextTick(() => lucide.createIcons()); },
        resetForm() { this.form = { profile_name: '', total_days: 14, max_threads_per_day: 10 }; },
        getStagePercent(p, stage) { const days = this.getStageDays(p, stage); return p.total_days ? Math.round((days / p.total_days) * 100) : 0; },
        getStageDays(p, stage) { return (p.daily_rules || []).filter(r => r.stage === stage).length; },
        viewProfile(p) { this.detailProfile = p; this.showDetail = true; this.$nextTick(() => lucide.createIcons()); },
        async saveProfile() {
            try { await apiCall('/api/warmup/profiles', 'POST', this.form); showToast('Profile created'); this.showModal = false; await this.load(); }
            catch(e) { showToast('Error: ' + e.message, 'error'); }
        },
        async deleteProfile(id) { if (!confirm('Delete this profile?')) return; try { await apiCall(`/api/warmup/profiles/${id}`, 'DELETE'); showToast('Deleted'); await this.load(); } catch(e) { showToast('Error', 'error'); } }
    };
}
</script>
@endpush
