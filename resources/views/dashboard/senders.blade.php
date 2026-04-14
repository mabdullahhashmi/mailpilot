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
            <button @click="openBulkModal()" class="px-4 py-2.5 rounded-xl text-sm font-medium flex items-center gap-2 text-cyan-300 bg-cyan-500/10 hover:bg-cyan-500/15 border border-cyan-500/30 transition">
                <i data-lucide="table-2" class="w-4 h-4"></i> Bulk Table Add
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
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="s.daily_send_cap ?? s.warmup_target_daily ?? '—'"></td>
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="s.domain?.domain_name ?? '—'"></td>
                        <td class="px-5 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button @click="testSmtp(s)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-blue-400" title="Test SMTP">
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

    <!-- Bulk Table Modal -->
    <div x-show="showBulkModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-3 modal-overlay" @click.self="showBulkModal = false" @paste="handleBulkPaste($event)">
        <div class="w-[min(98vw,1500px)] max-h-[94vh] overflow-y-auto glass rounded-2xl p-5 fade-in" @click.stop>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-white font-semibold text-lg">Bulk Add Sender Mailboxes</h3>
                    <p class="text-zinc-500 text-xs mt-1">Use one row per sender and set unique SMTP/IMAP host, port, username, password, and encryption.</p>
                </div>
                <button @click="showBulkModal = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>

            <div class="mb-4 p-3 rounded-xl bg-cyan-500/10 border border-cyan-500/25">
                <p class="text-cyan-300 text-xs">Tip: choose provider to auto-fill common hosts/ports, then adjust any row manually for custom SMTP/IMAP setups.</p>
                <p class="text-cyan-200/80 text-[11px] mt-1">Paste shortcut: copy rows from Google Sheets and press Ctrl+V anywhere in this modal to auto-fill the table.</p>
            </div>

            <div x-show="bulkResult" class="mb-4 p-3 rounded-xl" :class="bulkResult?.skipped > 0 ? 'bg-amber-500/10 border border-amber-500/25' : 'bg-emerald-500/10 border border-emerald-500/25'">
                <p class="text-sm font-medium" :class="bulkResult?.skipped > 0 ? 'text-amber-400' : 'text-emerald-400'">
                    <span x-text="bulkResult?.imported || 0"></span> imported,
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

            <div class="flex items-center gap-2 mb-3">
                <button type="button" @click="addBulkRow()" class="px-3 py-2 rounded-lg text-xs text-zinc-200 bg-white/5 hover:bg-white/10 border border-white/10">+ Add Row</button>
                <button type="button" @click="duplicateLastBulkRow()" class="px-3 py-2 rounded-lg text-xs text-zinc-200 bg-white/5 hover:bg-white/10 border border-white/10">Duplicate Last</button>
                <button type="button" @click="resetBulkRows()" class="px-3 py-2 rounded-lg text-xs text-zinc-300 bg-white/0 hover:bg-white/5 border border-white/10">Reset</button>
                <span class="ml-auto text-[11px] text-zinc-500" x-text="bulkRows.length + ' row(s)'"></span>
            </div>

            <div class="overflow-x-auto border border-white/10 rounded-xl">
                <table class="min-w-[1700px] w-full">
                    <thead>
                        <tr class="border-b border-white/10 bg-white/[0.02]">
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Email</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Provider</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">SMTP Host</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">SMTP Port</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">SMTP Username</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">SMTP Password</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">SMTP Enc.</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">IMAP Host</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">IMAP Port</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">IMAP Username</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">IMAP Password</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">IMAP Enc.</th>
                            <th class="text-left px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Daily Target</th>
                            <th class="text-right px-2 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, idx) in bulkRows" :key="idx">
                            <tr class="border-b border-white/5 hover:bg-white/[0.02]">
                                <td class="px-2 py-2">
                                    <input type="email" x-model="row.email_address" @change="syncBulkUsernames(row)" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white" placeholder="sender@domain.com">
                                </td>
                                <td class="px-2 py-2">
                                    <select x-model="row.provider" @change="applyProviderDefaultsToRow(row)" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white">
                                        <option value="">custom</option>
                                        <option value="google">google</option>
                                        <option value="microsoft">microsoft</option>
                                        <option value="zoho">zoho</option>
                                        <option value="yahoo">yahoo</option>
                                    </select>
                                </td>
                                <td class="px-2 py-2">
                                    <input type="text" x-model="row.smtp_host" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white" placeholder="smtp.host.com">
                                </td>
                                <td class="px-2 py-2">
                                    <input type="number" x-model.number="row.smtp_port" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white" placeholder="587">
                                </td>
                                <td class="px-2 py-2">
                                    <input type="text" x-model="row.smtp_username" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white" placeholder="smtp username">
                                </td>
                                <td class="px-2 py-2">
                                    <input type="text" x-model="row.smtp_password" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white" placeholder="smtp password/app password">
                                </td>
                                <td class="px-2 py-2">
                                    <select x-model="row.smtp_encryption" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white">
                                        <option value="tls">tls</option>
                                        <option value="ssl">ssl</option>
                                        <option value="none">none</option>
                                    </select>
                                </td>
                                <td class="px-2 py-2">
                                    <input type="text" x-model="row.imap_host" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white" placeholder="imap.host.com">
                                </td>
                                <td class="px-2 py-2">
                                    <input type="number" x-model.number="row.imap_port" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white" placeholder="993">
                                </td>
                                <td class="px-2 py-2">
                                    <input type="text" x-model="row.imap_username" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white" placeholder="imap username">
                                </td>
                                <td class="px-2 py-2">
                                    <input type="text" x-model="row.imap_password" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white" placeholder="imap password">
                                </td>
                                <td class="px-2 py-2">
                                    <select x-model="row.imap_encryption" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white">
                                        <option value="ssl">ssl</option>
                                        <option value="tls">tls</option>
                                        <option value="none">none</option>
                                    </select>
                                </td>
                                <td class="px-2 py-2">
                                    <input type="number" x-model.number="row.warmup_target_daily" class="input-dark w-full px-2 py-1.5 rounded-lg text-xs text-white" min="1" placeholder="20">
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <button type="button" @click="removeBulkRow(idx)" class="px-2 py-1 rounded-md text-[11px] text-red-300 hover:text-red-200 hover:bg-red-500/10 border border-red-500/20">Remove</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-end gap-3 mt-4">
                <button type="button" @click="showBulkModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Close</button>
                <button type="button" @click="saveBulkSenders()" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium" :disabled="bulkSaving">
                    <span x-show="!bulkSaving">Create in Bulk</span>
                    <span x-show="bulkSaving">Creating...</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showModal = false">
        <div class="w-full max-w-xl max-h-[90vh] overflow-y-auto glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-white font-semibold text-lg" x-text="editMode ? 'Edit Sender' : 'Add Sender Mailbox'"></h3>
                <button @click="showModal = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <form @submit.prevent="saveSender()" class="space-y-4">

                <!-- Email + Provider -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Email Address *</label>
                        <input type="email" x-model="form.email_address" @change="syncUsername()" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="sender@yourdomain.com" required :disabled="editMode">
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Provider *</label>
                        <select x-model="form.provider" @change="applyProviderDefaults()" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                            <option value="">Custom / Other</option>
                            <option value="google">Google (Gmail)</option>
                            <option value="microsoft">Microsoft (Outlook/365)</option>
                            <option value="zoho">Zoho Mail</option>
                            <option value="yahoo">Yahoo Mail</option>
                        </select>
                    </div>
                </div>

                <!-- App Password notice for Google -->
                <div x-show="form.provider === 'google'" class="flex items-start gap-2 p-3 rounded-xl bg-blue-500/10 border border-blue-500/20">
                    <i data-lucide="info" class="w-4 h-4 text-blue-400 flex-shrink-0 mt-0.5"></i>
                    <p class="text-blue-300 text-xs">Gmail requires an <strong>App Password</strong>, not your regular password. Enable 2-Step Verification then go to <span class="font-mono text-blue-200">myaccount.google.com → Security → App passwords</span> to generate one.</p>
                </div>

                <!-- Password -->
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium" x-text="form.provider === 'google' || form.provider === 'microsoft' ? 'App Password *' : 'Password *'"></label>
                    <input type="password" x-model="form.smtp_password" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" :required="!editMode"
                           :placeholder="form.provider === 'google' ? '16-character app password' : form.provider === 'microsoft' ? 'Account or app password' : 'SMTP password'">
                    <p x-show="editMode" class="text-zinc-500 text-[10px] mt-1">Leave blank to keep existing password</p>
                </div>

                <!-- SMTP Section -->
                <div class="border border-white/8 rounded-xl p-4 space-y-3">
                    <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
                        <i data-lucide="send" class="w-3.5 h-3.5"></i> SMTP (Outgoing)
                        <span x-show="form.provider === 'google' || form.provider === 'microsoft'" class="text-[10px] text-emerald-400 font-normal normal-case tracking-normal">Auto-filled</span>
                    </p>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2">
                            <label class="block text-xs text-zinc-500 mb-1">Host</label>
                            <input type="text" x-model="form.smtp_host" class="input-dark w-full px-3 py-2 rounded-lg text-sm text-white"
                                   :class="(form.provider === 'google' || form.provider === 'microsoft') ? 'opacity-60 cursor-not-allowed' : ''"
                                   :readonly="form.provider === 'google' || form.provider === 'microsoft'" required>
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-500 mb-1">Port</label>
                            <input type="number" x-model="form.smtp_port" class="input-dark w-full px-3 py-2 rounded-lg text-sm text-white"
                                   :class="(form.provider === 'google' || form.provider === 'microsoft') ? 'opacity-60 cursor-not-allowed' : ''"
                                   :readonly="form.provider === 'google' || form.provider === 'microsoft'" required>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-zinc-500 mb-1">Username</label>
                            <input type="text" x-model="form.smtp_username" class="input-dark w-full px-3 py-2 rounded-lg text-sm text-white" required>
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-500 mb-1">Encryption</label>
                            <select x-model="form.smtp_encryption" class="input-dark w-full px-3 py-2 rounded-lg text-sm text-white"
                                    :disabled="form.provider === 'google' || form.provider === 'microsoft'">
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- IMAP Section -->
                <div class="border border-white/8 rounded-xl p-4 space-y-3">
                    <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
                        <i data-lucide="inbox" class="w-3.5 h-3.5"></i> IMAP (Incoming — required for delivery verification)
                        <span x-show="form.provider === 'google' || form.provider === 'microsoft'" class="text-[10px] text-emerald-400 font-normal normal-case tracking-normal">Auto-filled</span>
                    </p>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2">
                            <label class="block text-xs text-zinc-500 mb-1">Host</label>
                            <input type="text" x-model="form.imap_host" class="input-dark w-full px-3 py-2 rounded-lg text-sm text-white"
                                   :class="(form.provider === 'google' || form.provider === 'microsoft') ? 'opacity-60 cursor-not-allowed' : ''"
                                   :readonly="form.provider === 'google' || form.provider === 'microsoft'">
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-500 mb-1">Port</label>
                            <input type="number" x-model="form.imap_port" class="input-dark w-full px-3 py-2 rounded-lg text-sm text-white"
                                   :class="(form.provider === 'google' || form.provider === 'microsoft') ? 'opacity-60 cursor-not-allowed' : ''"
                                   :readonly="form.provider === 'google' || form.provider === 'microsoft'">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-zinc-500 mb-1">Username</label>
                            <input type="text" x-model="form.imap_username" class="input-dark w-full px-3 py-2 rounded-lg text-sm text-white">
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-500 mb-1">Encryption</label>
                            <select x-model="form.imap_encryption" class="input-dark w-full px-3 py-2 rounded-lg text-sm text-white"
                                    :disabled="form.provider === 'google' || form.provider === 'microsoft'">
                                <option value="ssl">SSL</option>
                                <option value="tls">TLS</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Warmup Daily Cap -->
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Warmup Daily Target</label>
                    <input type="number" x-model="form.warmup_target_daily" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="20" min="1">
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 hover:text-white btn-ghost">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium" :disabled="saving">
                        <span x-show="!saving" x-text="editMode ? 'Update Sender' : 'Add Sender'"></span>
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
const PROVIDER_DEFAULTS = {
    google:    { smtp_host: 'smtp.gmail.com',        smtp_port: 587, smtp_encryption: 'tls', imap_host: 'imap.gmail.com',           imap_port: 993, imap_encryption: 'ssl' },
    microsoft: { smtp_host: 'smtp.office365.com',    smtp_port: 587, smtp_encryption: 'tls', imap_host: 'outlook.office365.com',    imap_port: 993, imap_encryption: 'ssl' },
    zoho:      { smtp_host: 'smtp.zoho.com',         smtp_port: 587, smtp_encryption: 'tls', imap_host: 'imap.zoho.com',            imap_port: 993, imap_encryption: 'ssl' },
    yahoo:     { smtp_host: 'smtp.mail.yahoo.com',   smtp_port: 587, smtp_encryption: 'tls', imap_host: 'imap.mail.yahoo.com',      imap_port: 993, imap_encryption: 'ssl' },
};

function sendersPage() {
    return {
        senders: [],
        showModal: false,
        showImport: false,
        showBulkModal: false,
        editMode: false,
        editId: null,
        saving: false,
        importFile: null,
        importing: false,
        importResult: null,
        bulkRows: [],
        bulkSaving: false,
        bulkResult: null,
        form: {},

        async init() {
            this.resetForm();
            this.resetBulkRows();
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
            this.form = { email_address: '', provider: '', smtp_host: '', smtp_port: 587, smtp_username: '', smtp_password: '', smtp_encryption: 'tls', imap_host: '', imap_port: 993, imap_username: '', imap_password: '', imap_encryption: 'ssl', warmup_target_daily: 20 };
        },

        applyProviderDefaults() {
            const d = PROVIDER_DEFAULTS[this.form.provider];
            if (d) {
                this.form.smtp_host = d.smtp_host;
                this.form.smtp_port = d.smtp_port;
                this.form.smtp_encryption = d.smtp_encryption;
                this.form.imap_host = d.imap_host;
                this.form.imap_port = d.imap_port;
                this.form.imap_encryption = d.imap_encryption;
            }
            this.syncUsername();
        },

        syncUsername() {
            if (this.form.email_address && !this.form.smtp_username) {
                this.form.smtp_username = this.form.email_address;
                this.form.imap_username = this.form.email_address;
            }
        },

        openBulkModal() {
            this.showBulkModal = true;
            this.bulkResult = null;
            if (!this.bulkRows.length) this.resetBulkRows();
            this.$nextTick(() => lucide.createIcons());
        },

        newBulkRow() {
            return {
                email_address: '',
                provider: '',
                smtp_host: '',
                smtp_port: 587,
                smtp_username: '',
                smtp_password: '',
                smtp_encryption: 'tls',
                imap_host: '',
                imap_port: 993,
                imap_username: '',
                imap_password: '',
                imap_encryption: 'ssl',
                warmup_target_daily: 20,
            };
        },

        resetBulkRows() {
            this.bulkRows = [this.newBulkRow()];
        },

        addBulkRow() {
            this.bulkRows.push(this.newBulkRow());
        },

        duplicateLastBulkRow() {
            const last = this.bulkRows[this.bulkRows.length - 1] || this.newBulkRow();
            this.bulkRows.push({
                ...last,
                email_address: '',
                smtp_username: '',
                smtp_password: '',
                imap_username: '',
                imap_password: '',
            });
        },

        removeBulkRow(index) {
            if (this.bulkRows.length <= 1) {
                this.resetBulkRows();
                return;
            }

            this.bulkRows.splice(index, 1);
        },

        applyProviderDefaultsToRow(row) {
            const d = PROVIDER_DEFAULTS[row.provider];
            if (d) {
                row.smtp_host = d.smtp_host;
                row.smtp_port = d.smtp_port;
                row.smtp_encryption = d.smtp_encryption;
                row.imap_host = d.imap_host;
                row.imap_port = d.imap_port;
                row.imap_encryption = d.imap_encryption;
            }
            this.syncBulkUsernames(row);
        },

        syncBulkUsernames(row) {
            if (row.email_address && !row.smtp_username) {
                row.smtp_username = row.email_address;
            }
            if (row.email_address && !row.imap_username) {
                row.imap_username = row.email_address;
            }
        },

        bulkColumnOrder() {
            return [
                'email_address',
                'provider',
                'smtp_host',
                'smtp_port',
                'smtp_username',
                'smtp_password',
                'smtp_encryption',
                'imap_host',
                'imap_port',
                'imap_username',
                'imap_password',
                'imap_encryption',
                'warmup_target_daily',
            ];
        },

        bulkHeaderFieldMap() {
            return {
                email: 'email_address',
                email_address: 'email_address',
                sender_email: 'email_address',
                provider: 'provider',
                smtp_host: 'smtp_host',
                smtp_server: 'smtp_host',
                smtp: 'smtp_host',
                smtp_port: 'smtp_port',
                smtp_username: 'smtp_username',
                smtp_user: 'smtp_username',
                smtp_password: 'smtp_password',
                smtp_pass: 'smtp_password',
                smtp_app_password: 'smtp_password',
                app_password: 'smtp_password',
                password: 'smtp_password',
                smtp_encryption: 'smtp_encryption',
                smtp_enc: 'smtp_encryption',
                smtp_security: 'smtp_encryption',
                imap_host: 'imap_host',
                imap_server: 'imap_host',
                imap: 'imap_host',
                imap_port: 'imap_port',
                imap_username: 'imap_username',
                imap_user: 'imap_username',
                imap_password: 'imap_password',
                imap_pass: 'imap_password',
                imap_encryption: 'imap_encryption',
                imap_enc: 'imap_encryption',
                imap_security: 'imap_encryption',
                warmup_target_daily: 'warmup_target_daily',
                warmup_daily_target: 'warmup_target_daily',
                daily_target: 'warmup_target_daily',
                daily_cap: 'warmup_target_daily',
            };
        },

        normalizePasteHeader(value) {
            return String(value || '')
                .trim()
                .toLowerCase()
                .replace(/\s+/g, '_')
                .replace(/[^a-z0-9_]/g, '_')
                .replace(/_+/g, '_')
                .replace(/^_+|_+$/g, '');
        },

        normalizeProviderFromPaste(value) {
            const raw = String(value || '').trim().toLowerCase();
            if (!raw) return '';
            if (['google', 'gmail', 'g-suite', 'gsuite'].includes(raw)) return 'google';
            if (['microsoft', 'outlook', 'office365', 'office_365', 'm365'].includes(raw)) return 'microsoft';
            if (['zoho'].includes(raw)) return 'zoho';
            if (['yahoo'].includes(raw)) return 'yahoo';
            return raw;
        },

        normalizeEncryptionFromPaste(value) {
            const raw = String(value || '').trim().toLowerCase();
            if (!raw) return '';
            if (['starttls', 'tls'].includes(raw)) return 'tls';
            if (['ssl'].includes(raw)) return 'ssl';
            if (['none', 'no', 'plain'].includes(raw)) return 'none';
            return raw;
        },

        normalizeBulkFieldValue(field, value) {
            const text = String(value ?? '').trim();
            if (text === '') return '';

            if (field === 'provider') {
                return this.normalizeProviderFromPaste(text);
            }

            if (field === 'smtp_encryption' || field === 'imap_encryption') {
                return this.normalizeEncryptionFromPaste(text);
            }

            if (field === 'smtp_port' || field === 'imap_port' || field === 'warmup_target_daily') {
                const n = Number.parseInt(text, 10);
                return Number.isFinite(n) ? n : '';
            }

            return text;
        },

        fillProviderDefaultsIfMissing(row) {
            const d = PROVIDER_DEFAULTS[row.provider];
            if (!d) return;

            if (!row.smtp_host) row.smtp_host = d.smtp_host;
            if (!row.smtp_port) row.smtp_port = d.smtp_port;
            if (!row.smtp_encryption) row.smtp_encryption = d.smtp_encryption;
            if (!row.imap_host) row.imap_host = d.imap_host;
            if (!row.imap_port) row.imap_port = d.imap_port;
            if (!row.imap_encryption) row.imap_encryption = d.imap_encryption;
        },

        parseBulkClipboardText(text) {
            const normalizedText = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const lines = normalizedText.split('\n').filter((line) => line.trim() !== '');
            if (!lines.length) return [];

            const grid = lines.map((line) => line.split('\t'));
            const headerFieldMap = this.bulkHeaderFieldMap();
            const firstHeaders = grid[0].map((cell) => this.normalizePasteHeader(cell));
            const hasHeader = firstHeaders.some((h) => !!headerFieldMap[h]);

            const columnOrder = hasHeader
                ? firstHeaders.map((h) => headerFieldMap[h] || null)
                : this.bulkColumnOrder();

            const startIndex = hasHeader ? 1 : 0;
            const rows = [];

            for (let i = startIndex; i < grid.length; i++) {
                const row = this.newBulkRow();
                const cells = grid[i];

                cells.forEach((cell, index) => {
                    const field = columnOrder[index];
                    if (!field) return;
                    row[field] = this.normalizeBulkFieldValue(field, cell);
                });

                this.fillProviderDefaultsIfMissing(row);
                this.syncBulkUsernames(row);

                const hasAnyData = this.bulkColumnOrder().some((field) => String(row[field] ?? '').trim() !== '');
                if (!hasAnyData) continue;

                rows.push(row);
            }

            return rows;
        },

        handleBulkPaste(event) {
            if (!this.showBulkModal) return;

            const text = event?.clipboardData?.getData('text/plain') || '';
            if (!text || (!text.includes('\t') && !text.includes('\n') && !text.includes('\r'))) {
                return;
            }

            event.preventDefault();

            const parsedRows = this.parseBulkClipboardText(text);
            if (!parsedRows.length) {
                showToast('Could not parse pasted sheet data.', 'error');
                return;
            }

            this.bulkRows = parsedRows;
            this.bulkResult = null;
            showToast(`Pasted ${parsedRows.length} row(s) from Google Sheets.`, 'success');
        },

        editSender(s) {
            this.editMode = true;
            this.editId = s.id;
            this.form = { ...s, smtp_password: '', imap_password: '' };
            this.showModal = true;
        },

        async saveSender() {
            this.saving = true;
            try {
                const data = { ...this.form };
                if (this.editMode && !data.smtp_password) delete data.smtp_password;
                if (this.editMode && !data.imap_password) delete data.imap_password;
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

        async testSmtp(sender) {
            const target = prompt(
                'Enter recipient email for test message.\nLeave empty to only test SMTP connection.',
                sender?.email_address || ''
            );
            if (target === null) return;

            const email = (target || '').trim();
            const payload = email ? { test_email: email } : null;

            showToast(email ? 'Testing SMTP and sending test email...' : 'Testing SMTP connection...', 'info');
            try {
                const res = await apiCall(`/api/warmup/sender-mailboxes/${sender.id}/test-smtp`, 'POST', payload);
                const msg = res.message || (res.success ? 'SMTP connected!' : 'SMTP test failed');
                showToast(msg, res.success ? 'success' : 'error');
                await this.load();
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

        async saveBulkSenders() {
            this.bulkSaving = true;
            this.bulkResult = null;

            try {
                const rows = this.bulkRows
                    .map((row) => ({
                        email_address: (row.email_address || '').trim(),
                        provider: (row.provider || '').trim() || null,
                        smtp_host: (row.smtp_host || '').trim(),
                        smtp_port: row.smtp_port === '' || row.smtp_port === null ? null : Number(row.smtp_port),
                        smtp_username: (row.smtp_username || '').trim(),
                        smtp_password: row.smtp_password || '',
                        smtp_encryption: (row.smtp_encryption || 'tls').trim(),
                        imap_host: (row.imap_host || '').trim() || null,
                        imap_port: row.imap_port === '' || row.imap_port === null ? null : Number(row.imap_port),
                        imap_username: (row.imap_username || '').trim() || null,
                        imap_password: row.imap_password || null,
                        imap_encryption: (row.imap_encryption || '').trim() || null,
                        warmup_target_daily: row.warmup_target_daily === '' || row.warmup_target_daily === null ? null : Number(row.warmup_target_daily),
                    }))
                    .filter((row) => [
                        row.email_address,
                        row.smtp_host,
                        row.smtp_username,
                        row.smtp_password,
                        row.imap_host || '',
                        row.imap_username || '',
                        row.imap_password || '',
                    ].some((v) => String(v).trim() !== ''));

                if (!rows.length) {
                    showToast('Add at least one non-empty row before bulk create.', 'error');
                    return;
                }

                const res = await apiCall('/api/warmup/sender-mailboxes/bulk', 'POST', { rows });
                this.bulkResult = res;

                if ((res.imported || 0) > 0) {
                    await this.load();
                }

                if ((res.skipped || 0) > 0) {
                    showToast(`Bulk create done: ${res.imported || 0} imported, ${res.skipped || 0} skipped.`, 'info');
                } else {
                    showToast(`Bulk create done: ${res.imported || 0} sender(s) imported.`, 'success');
                }
            } catch (e) {
                showToast('Bulk create error: ' + e.message, 'error');
            } finally {
                this.bulkSaving = false;
            }
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
