@extends('layouts.app')
@section('title', 'Sender Mailboxes')
@section('page-title', 'Sender Mailboxes')
@section('page-description', 'Manage your sending email accounts')

@section('content')
<div x-data="sendersPage()" x-init="init()">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <span class="text-zinc-500 text-sm" x-text="senders.length + ' mailboxes'"></span>
        </div>
        <div class="flex items-center gap-2">
            <button @click="showImport = true" class="px-4 py-2.5 rounded-xl text-sm font-medium flex items-center gap-2 text-zinc-300 bg-white/5 hover:bg-white/10 border border-white/10 transition">
                <i data-lucide="upload" class="w-4 h-4"></i> CSV Import
            </button>
            <button @click="showModal = true; editMode = false; resetForm()" class="btn-primary px-4 py-2.5 rounded-xl text-white text-sm font-medium flex items-center gap-2">
                <i data-lucide="plus" class="w-4 h-4"></i> Add Sender
            </button>
        </div>
    </div>

    <!-- Table -->
    <div class="glass rounded-2xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/5">
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Email</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Provider</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Status</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Daily Cap</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Domain</th>
                    <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Actions</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="s in senders" :key="s.id">
                    <tr class="table-row border-b border-white/[0.03]">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg gradient-brand flex items-center justify-center text-white text-[10px] font-bold" x-text="s.email_address?.charAt(0).toUpperCase()"></div>
                                <span class="text-sm text-white font-medium" x-text="s.email_address"></span>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="s.provider || '—'"></td>
                        <td class="px-5 py-4">
                            <span class="badge px-2 py-0.5 rounded-full"
                                  :class="s.status === 'active' ? 'bg-emerald-500/15 text-emerald-400' : s.status === 'paused' ? 'bg-amber-500/15 text-amber-400' : 'bg-zinc-500/15 text-zinc-400'"
                                  x-text="s.status"></span>
                        </td>
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="s.warmup_target_daily ?? '—'"></td>
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="s.domain?.domain_name ?? '—'"></td>
                        <td class="px-5 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button @click="testSmtp(s.id)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-blue-400" title="Test SMTP">
                                    <i data-lucide="plug" class="w-4 h-4"></i>
                                </button>
                                <button @click="togglePause(s)" class="btn-ghost p-2 rounded-lg text-zinc-500" :class="s.status === 'active' ? 'hover:text-amber-400' : 'hover:text-emerald-400'" :title="s.status === 'active' ? 'Pause' : 'Resume'">
                                    <i :data-lucide="s.status === 'active' ? 'pause' : 'play'" class="w-4 h-4"></i>
                                </button>
                                <button @click="editSender(s)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-brand-400" title="Edit">
                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                </button>
                                <button @click="deleteSender(s.id)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-red-400" title="Delete">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="senders.length === 0" class="text-center py-16">
            <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-4">
                <i data-lucide="send" class="w-7 h-7 text-zinc-600"></i>
            </div>
            <p class="text-zinc-400 font-medium">No sender mailboxes yet</p>
            <p class="text-zinc-600 text-sm mt-1">Add your first sending email account to start warming up</p>
        </div>
    </div>

    <!-- CSV Import Modal -->
    <div x-show="showImport" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showImport = false">
        <div class="w-full max-w-lg glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-white font-semibold text-lg">Import Senders from CSV</h3>
                <button @click="showImport = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div class="mb-4 p-3 rounded-xl bg-blue-500/10 border border-blue-500/20">
                <p class="text-blue-400 text-xs font-medium mb-1">Required CSV columns:</p>
                <p class="text-blue-300/70 text-[11px] font-mono">email_address, smtp_host, smtp_port, smtp_username, smtp_password</p>
                <p class="text-zinc-500 text-[11px] mt-1">Optional: smtp_encryption, provider, warmup_target_daily</p>
            </div>
            <form @submit.prevent="uploadCsv()" class="space-y-4">
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">CSV File *</label>
                    <input type="file" accept=".csv,.txt" @change="importFile = $event.target.files[0]"
                           class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-brand-500/20 file:text-brand-400 file:cursor-pointer">
                </div>
                <div x-show="importResult" class="p-3 rounded-xl" :class="importResult?.skipped > 0 ? 'bg-amber-500/10 border border-amber-500/20' : 'bg-emerald-500/10 border border-emerald-500/20'">
                    <p class="text-sm font-medium" :class="importResult?.skipped > 0 ? 'text-amber-400' : 'text-emerald-400'">
                        <span x-text="importResult?.imported || 0"></span> imported,
                        <span x-text="importResult?.skipped || 0"></span> skipped
                    </p>
                    <template x-if="importResult?.errors?.length">
                        <ul class="mt-2 space-y-0.5">
                            <template x-for="err in importResult.errors" :key="err">
                                <li class="text-red-400/80 text-[11px]" x-text="err"></li>
                            </template>
                        </ul>
                    </template>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showImport = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Close</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium" :disabled="importing || !importFile">
                        <span x-show="!importing">Upload & Import</span>
                        <span x-show="importing">Importing...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showModal = false">
        <div class="w-full max-w-lg glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-white font-semibold text-lg" x-text="editMode ? 'Edit Sender' : 'Add Sender Mailbox'"></h3>
                <button @click="showModal = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <form @submit.prevent="saveSender()" class="space-y-4">
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Email Address *</label>
                    <input type="email" x-model="form.email_address" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="sender@yourdomain.com" required :disabled="editMode">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">SMTP Host *</label>
                        <input type="text" x-model="form.smtp_host" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="smtp.gmail.com" required>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">SMTP Port *</label>
                        <input type="number" x-model="form.smtp_port" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="587" required>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">SMTP Username *</label>
                        <input type="text" x-model="form.smtp_username" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" required>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">SMTP Password *</label>
                        <input type="password" x-model="form.smtp_password" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" :required="!editMode">
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Encryption *</label>
                    <select x-model="form.smtp_encryption" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="none">None</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Provider</label>
                        <select x-model="form.provider" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                            <option value="">Auto-detect</option>
                            <option value="google">Google</option>
                            <option value="microsoft">Microsoft</option>
                            <option value="zoho">Zoho</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Daily Cap</label>
                        <input type="number" x-model="form.warmup_target_daily" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="20">
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 hover:text-white btn-ghost">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium" :disabled="saving">
                        <span x-show="!saving" x-text="editMode ? 'Update' : 'Add Sender'"></span>
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
function sendersPage() {
    return {
        senders: [],
        showModal: false,
        showImport: false,
        editMode: false,
        editId: null,
        saving: false,
        importFile: null,
        importing: false,
        importResult: null,
        form: {},

        async init() {
            await this.load();
            this.$nextTick(() => lucide.createIcons());
        },

        async load() {
            try {
                this.senders = await apiCall('/api/warmup/sender-mailboxes');
            } catch(e) { this.senders = []; }
            this.$nextTick(() => lucide.createIcons());
        },

        resetForm() {
            this.form = { email_address: '', smtp_host: '', smtp_port: 587, smtp_username: '', smtp_password: '', smtp_encryption: 'tls', provider: '', warmup_target_daily: 20 };
        },

        editSender(s) {
            this.editMode = true;
            this.editId = s.id;
            this.form = { ...s, smtp_password: '' };
            this.showModal = true;
        },

        async saveSender() {
            this.saving = true;
            try {
                const data = { ...this.form };
                if (this.editMode && !data.smtp_password) delete data.smtp_password;
                if (this.editMode) {
                    await apiCall(`/api/warmup/sender-mailboxes/${this.editId}`, 'PUT', data);
                    showToast('Sender updated');
                } else {
                    await apiCall('/api/warmup/sender-mailboxes', 'POST', data);
                    showToast('Sender added');
                }
                this.showModal = false;
                await this.load();
            } catch(e) { showToast('Error: ' + e.message, 'error'); }
            this.saving = false;
        },

        async deleteSender(id) {
            if (!confirm('Delete this sender mailbox?')) return;
            try {
                await apiCall(`/api/warmup/sender-mailboxes/${id}`, 'DELETE');
                showToast('Sender deleted');
                await this.load();
            } catch(e) { showToast('Error: ' + e.message, 'error'); }
        },

        async testSmtp(id) {
            showToast('Testing SMTP connection...', 'info');
            try {
                const res = await apiCall(`/api/warmup/sender-mailboxes/${id}/test-smtp`, 'POST');
                showToast(res.success ? 'SMTP connected!' : 'SMTP failed: ' + res.message, res.success ? 'success' : 'error');
            } catch(e) { showToast('Test failed: ' + e.message, 'error'); }
        },

        async togglePause(s) {
            const action = s.status === 'active' ? 'pause' : 'resume';
            try {
                await apiCall(`/api/warmup/sender-mailboxes/${s.id}/${action}`, 'POST');
                showToast(`Sender ${action}d`);
                await this.load();
            } catch(e) { showToast('Error', 'error'); }
        },

        async uploadCsv() {
            if (!this.importFile) return;
            this.importing = true;
            this.importResult = null;
            try {
                const fd = new FormData();
                fd.append('csv_file', this.importFile);
                const res = await fetch('/api/warmup/import/senders', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: fd
                });
                if (!res.ok) { const err = await res.json(); throw new Error(err.error || err.message || 'Import failed'); }
                this.importResult = await res.json();
                showToast(`Imported ${this.importResult.imported} senders`, 'success');
                await this.load();
            } catch(e) { showToast('Error: ' + e.message, 'error'); }
            this.importing = false;
        }
    };
}
</script>
@endpush
