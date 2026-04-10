@extends('layouts.app')
@section('title', 'Content Templates')
@section('page-title', 'Content Templates')
@section('page-description', 'Manage email templates used by the warmup engine')

@section('content')
<div x-data="templatesPage()" x-init="init()">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <span class="text-zinc-500 text-sm" x-text="templates.length + ' templates'"></span>
            <div class="flex gap-1">
                <template x-for="f in ['all','initial','reply','closing']" :key="f">
                    <button @click="filter = f" class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                            :class="filter === f ? 'bg-brand-500/20 text-brand-400' : 'text-zinc-500 hover:text-zinc-300 hover:bg-white/5'"
                            x-text="f === 'all' ? 'All' : f.charAt(0).toUpperCase() + f.slice(1)"></button>
                </template>
            </div>
        </div>
        <button @click="showModal = true; editMode = false; resetForm()" class="btn-primary px-4 py-2.5 rounded-xl text-white text-sm font-medium flex items-center gap-2">
            <i data-lucide="plus" class="w-4 h-4"></i> New Template
        </button>
    </div>

    <!-- Template Table -->
    <div class="glass rounded-2xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/5">
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Subject / Type</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Category</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Stage</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Status</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Used</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Cooldown</th>
                    <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Actions</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="t in filtered" :key="t.id">
                    <tr class="table-row border-b border-white/[0.03]">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center"
                                     :class="t.template_type === 'initial' ? 'gradient-brand' : t.template_type === 'reply' ? 'gradient-success' : 'gradient-warning'">
                                    <i :data-lucide="t.template_type === 'initial' ? 'mail' : t.template_type === 'reply' ? 'reply' : 'check-circle'" class="w-4 h-4 text-white"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-white font-medium truncate max-w-[200px]" x-text="t.subject || '(No subject)'"></p>
                                    <span class="badge px-1.5 py-0.5 rounded text-[9px]"
                                          :class="t.template_type === 'initial' ? 'bg-brand-500/15 text-brand-400' : t.template_type === 'reply' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'"
                                          x-text="t.template_type"></span>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="t.category || '—'"></td>
                        <td class="px-5 py-4">
                            <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                  :class="stageBadge(t.warmup_stage)" x-text="(t.warmup_stage || 'any').replace('_',' ')"></span>
                        </td>
                        <td class="px-5 py-4">
                            <span class="badge px-2 py-0.5 rounded-full" :class="t.is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-zinc-500/15 text-zinc-400'"
                                  x-text="t.is_active ? 'Active' : 'Inactive'"></span>
                        </td>
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="t.usage_count || 0"></td>
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="(t.cooldown_minutes || 0) + 'm'"></td>
                        <td class="px-5 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button @click="toggleActive(t)" class="btn-ghost p-2 rounded-lg text-zinc-500"
                                        :class="t.is_active ? 'hover:text-amber-400' : 'hover:text-emerald-400'"
                                        :title="t.is_active ? 'Deactivate' : 'Activate'">
                                    <i :data-lucide="t.is_active ? 'pause' : 'play'" class="w-4 h-4"></i>
                                </button>
                                <button @click="editTemplate(t)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-brand-400" title="Edit">
                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                </button>
                                <button @click="deleteTemplate(t.id)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-red-400" title="Delete">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="filtered.length === 0" class="text-center py-16">
            <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-4">
                <i data-lucide="file-text" class="w-7 h-7 text-zinc-600"></i>
            </div>
            <p class="text-zinc-400 font-medium">No templates found</p>
            <p class="text-zinc-600 text-sm mt-1">Create templates to power the warmup engine's email content</p>
        </div>
    </div>

    <!-- Create/Edit Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showModal = false">
        <div class="w-full max-w-2xl max-h-[85vh] overflow-y-auto glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-white font-semibold text-lg" x-text="editMode ? 'Edit Template' : 'New Content Template'"></h3>
                <button @click="showModal = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <form @submit.prevent="saveTemplate()" class="space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Type *</label>
                        <select x-model="form.template_type" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" required>
                            <option value="initial">Initial</option>
                            <option value="reply">Reply</option>
                            <option value="closing">Closing</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Category</label>
                        <input type="text" x-model="form.category" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="e.g. business, casual">
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Warmup Stage</label>
                        <select x-model="form.warmup_stage" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                            <option value="any">Any</option>
                            <option value="ramp_up">Ramp Up</option>
                            <option value="plateau">Plateau</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Subject Line</label>
                    <input type="text" x-model="form.subject" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="e.g. Quick question about @{{var:topic}}">
                    <p class="text-zinc-600 text-[10px] mt-1">Use @{{var:name}} for variations, @{{greeting}}, @{{signoff}}, @{{sender_name}}, @{{recipient_name}}</p>
                </div>
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Body *</label>
                    <textarea x-model="form.body" rows="6" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white resize-y" required
                              placeholder="<p>@{{greeting}},</p><p>Your email body here...</p><p>@{{signoff}},<br>@{{sender_name}}</p>"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Greetings (comma-separated)</label>
                        <input type="text" x-model="greetingsStr" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="Hi, Hello, Hey">
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Sign-offs (comma-separated)</label>
                        <input type="text" x-model="signoffsStr" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="Best, Thanks, Regards">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Cooldown (minutes)</label>
                        <input type="number" x-model="form.cooldown_minutes" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" min="0">
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="form.is_active" class="w-4 h-4 rounded bg-white/10 border-white/20 text-brand-500 focus:ring-brand-500">
                            <span class="text-sm text-zinc-300">Active</span>
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium" :disabled="saving">
                        <span x-show="!saving" x-text="editMode ? 'Update Template' : 'Create Template'"></span>
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
function templatesPage() {
    return {
        templates: [], filter: 'all', showModal: false, editMode: false, editId: null, saving: false,
        form: {}, greetingsStr: '', signoffsStr: '',

        get filtered() {
            if (this.filter === 'all') return this.templates;
            return this.templates.filter(t => t.template_type === this.filter);
        },

        async init() { await this.load(); this.$nextTick(() => lucide.createIcons()); },

        async load() {
            try { this.templates = await apiCall('/api/warmup/content-templates'); } catch(e) { this.templates = []; }
            this.$nextTick(() => lucide.createIcons());
        },

        resetForm() {
            this.form = { template_type: 'initial', category: '', subject: '', body: '', warmup_stage: 'any', cooldown_minutes: 60, is_active: true };
            this.greetingsStr = 'Hi, Hello, Hey';
            this.signoffsStr = 'Best, Thanks, Regards';
        },

        editTemplate(t) {
            this.editMode = true;
            this.editId = t.id;
            this.form = { ...t };
            this.greetingsStr = (t.greetings || []).join(', ');
            this.signoffsStr = (t.signoffs || []).join(', ');
            this.showModal = true;
        },

        stageBadge(stage) {
            return { ramp_up: 'bg-brand-500/15 text-brand-400', plateau: 'bg-emerald-500/15 text-emerald-400', maintenance: 'bg-amber-500/15 text-amber-400', any: 'bg-zinc-500/15 text-zinc-400' }[stage] || 'bg-zinc-500/15 text-zinc-400';
        },

        async saveTemplate() {
            this.saving = true;
            try {
                const payload = { ...this.form };
                payload.greetings = this.greetingsStr.split(',').map(s => s.trim()).filter(Boolean);
                payload.signoffs = this.signoffsStr.split(',').map(s => s.trim()).filter(Boolean);
                if (this.editMode) {
                    await apiCall(`/api/warmup/content-templates/${this.editId}`, 'PUT', payload);
                    showToast('Template updated');
                } else {
                    await apiCall('/api/warmup/content-templates', 'POST', payload);
                    showToast('Template created');
                }
                this.showModal = false;
                await this.load();
            } catch(e) { showToast('Error: ' + e.message, 'error'); }
            this.saving = false;
        },

        async toggleActive(t) {
            try {
                await apiCall(`/api/warmup/content-templates/${t.id}`, 'PUT', { is_active: !t.is_active });
                showToast(t.is_active ? 'Template deactivated' : 'Template activated');
                await this.load();
            } catch(e) { showToast('Error', 'error'); }
        },

        async deleteTemplate(id) {
            if (!confirm('Delete this template?')) return;
            try { await apiCall(`/api/warmup/content-templates/${id}`, 'DELETE'); showToast('Deleted'); await this.load(); }
            catch(e) { showToast('Error', 'error'); }
        }
    };
}
</script>
@endpush
