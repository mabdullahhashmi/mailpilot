@extends('layouts.app')
@section('title', 'Seed Mailboxes')
@section('page-title', 'Seed Mailboxes')
@section('page-description', 'Manage seed inboxes for warmup interactions')

@section('content')
<div x-data="seedsPage()" x-init="init()">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <span class="text-zinc-500 text-sm" x-text="seeds.length + ' seeds'"></span>
        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto sm:justify-end">
            <button @click="checkAllSeedConnections()" class="px-4 py-2.5 rounded-xl text-sm font-medium flex items-center gap-2 text-cyan-200 bg-cyan-500/10 hover:bg-cyan-500/20 border border-cyan-500/30 disabled:opacity-60 disabled:cursor-not-allowed" :disabled="checkingAllConnections">
                <i data-lucide="shield-check" class="w-4 h-4"></i>
                <span x-show="!checkingAllConnections">Check All Connections</span>
                <span x-show="checkingAllConnections">Checking...</span>
            </button>
            <button @click="openQuickBulk()" class="px-4 py-2.5 rounded-xl text-sm font-medium flex items-center gap-2 text-blue-300 bg-blue-500/10 hover:bg-blue-500/20 border border-blue-500/20 transition">
                <i data-lucide="list-plus" class="w-4 h-4"></i> Quick Bulk Add
            </button>
            <button @click="showImport = true" class="px-4 py-2.5 rounded-xl text-sm font-medium flex items-center gap-2 text-zinc-300 bg-white/5 hover:bg-white/10 border border-white/10 transition">
                <i data-lucide="upload" class="w-4 h-4"></i> CSV Import
            </button>
            <button @click="showModal = true; editMode = false; resetForm()" class="btn-primary px-4 py-2.5 rounded-xl text-white text-sm font-medium flex items-center gap-2">
                <i data-lucide="plus" class="w-4 h-4"></i> Add Seed
            </button>
        </div>
    </div>

    <div x-show="allConnectionCheckResult" class="mb-4 p-4 rounded-xl border" :class="(allConnectionCheckResult?.with_issues || 0) > 0 ? 'bg-amber-500/10 border-amber-500/25' : 'bg-emerald-500/10 border-emerald-500/25'">
        <div class="flex flex-wrap gap-4 text-xs">
            <span class="text-zinc-300">Checked: <strong x-text="allConnectionCheckResult?.total || 0"></strong></span>
            <span class="text-emerald-300">Healthy: <strong x-text="allConnectionCheckResult?.fully_healthy || 0"></strong></span>
            <span class="text-amber-300">With Issues: <strong x-text="allConnectionCheckResult?.with_issues || 0"></strong></span>
            <span class="text-zinc-400">SMTP Pass/Fail: <strong x-text="(allConnectionCheckResult?.smtp_pass || 0) + '/' + (allConnectionCheckResult?.smtp_fail || 0)"></strong></span>
            <span class="text-zinc-400">IMAP Pass/Fail: <strong x-text="(allConnectionCheckResult?.imap_pass || 0) + '/' + (allConnectionCheckResult?.imap_fail || 0)"></strong></span>
        </div>

        <div x-show="(allConnectionCheckResult?.issues || []).length > 0" class="mt-3 max-h-52 overflow-y-auto space-y-1 pr-1">
            <template x-for="issue in (allConnectionCheckResult?.issues || [])" :key="issue.seed_id + '-' + issue.email_address">
                <div class="text-[11px] text-zinc-300 border border-white/10 rounded-lg px-2.5 py-2">
                    <p class="text-zinc-200 font-medium" x-text="issue.email_address"></p>
                    <p class="text-zinc-400" x-text="'SMTP: ' + (issue.smtp?.success ? 'OK' : (issue.smtp?.message || 'Failed'))"></p>
                    <p class="text-zinc-400" x-text="'IMAP: ' + (issue.imap?.success ? 'OK' : (issue.imap?.message || 'Failed'))"></p>
                </div>
            </template>
        </div>
    </div>

    <div class="glass rounded-2xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/5">
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Email</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Provider</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Status</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Checks</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Daily Cap</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Interactions</th>
                    <th class="text-right px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Actions</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="s in seeds" :key="s.id">
                    <tr class="table-row border-b border-white/[0.03]">
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg gradient-success flex items-center justify-center text-white text-[10px] font-bold" x-text="s.email_address?.charAt(0).toUpperCase()"></div>
                                <span class="text-sm text-white font-medium" x-text="s.email_address"></span>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="s.provider || '—'"></td>
                        <td class="px-5 py-4">
                            <span class="badge px-2 py-0.5 rounded-full"
                                :class="s.status === 'active' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-amber-500/15 text-amber-400'"
                                x-text="s.status"></span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex flex-wrap gap-1.5">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium"
                                    :class="(s.last_smtp_test_result || 'untested') === 'pass' ? 'bg-emerald-500/15 text-emerald-300' : ((s.last_smtp_test_result || 'untested') === 'fail' ? 'bg-red-500/15 text-red-300' : 'bg-zinc-500/15 text-zinc-400')"
                                    :title="s.last_smtp_test_at ? ('Last SMTP test: ' + formatDateTime(s.last_smtp_test_at)) : 'SMTP not tested yet'"
                                    x-text="'SMTP ' + (s.last_smtp_test_result || 'untested').toUpperCase()"></span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium"
                                    :class="(s.last_imap_test_result || 'untested') === 'pass' ? 'bg-emerald-500/15 text-emerald-300' : ((s.last_imap_test_result || 'untested') === 'fail' ? 'bg-red-500/15 text-red-300' : 'bg-zinc-500/15 text-zinc-400')"
                                    :title="s.last_imap_test_at ? ('Last IMAP test: ' + formatDateTime(s.last_imap_test_at)) : 'IMAP not tested yet'"
                                    x-text="'IMAP ' + (s.last_imap_test_result || 'untested').toUpperCase()"></span>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="s.daily_total_interaction_cap ?? s.daily_interaction_cap ?? 20"></td>
                        <td class="px-5 py-4 text-sm text-zinc-400" x-text="s.total_interactions ?? 0"></td>
                        <td class="px-5 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button @click="viewSeedHistory(s)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-blue-400" title="View History">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                </button>
                                <button @click="openInbox(s)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-cyan-400" title="Open Inbox">
                                    <i data-lucide="mail-open" class="w-4 h-4"></i>
                                </button>
                                <button @click="testSmtp(s)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-sky-400 disabled:opacity-40 disabled:cursor-not-allowed" :disabled="isSeedCheckBusy(s.id, 'smtp')" title="Test SMTP">
                                    <i data-lucide="plug" class="w-4 h-4"></i>
                                </button>
                                <button @click="testImap(s)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-cyan-400 disabled:opacity-40 disabled:cursor-not-allowed" :disabled="isSeedCheckBusy(s.id, 'imap')" title="Test IMAP">
                                    <i data-lucide="inbox" class="w-4 h-4"></i>
                                </button>
                                <button @click="togglePause(s)" class="btn-ghost p-2 rounded-lg text-zinc-500" :class="s.status === 'active' ? 'hover:text-amber-400' : 'hover:text-emerald-400'">
                                    <i :data-lucide="s.status === 'active' ? 'pause' : 'play'" class="w-4 h-4"></i>
                                </button>
                                <button @click="editSeed(s)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-brand-400">
                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                </button>
                                <button @click="deleteSeed(s.id)" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-red-400">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="seeds.length === 0" class="text-center py-16">
            <div class="w-16 h-16 rounded-2xl glass flex items-center justify-center mx-auto mb-4">
                <i data-lucide="inbox" class="w-7 h-7 text-zinc-600"></i>
            </div>
            <p class="text-zinc-400 font-medium">No seed mailboxes yet</p>
            <p class="text-zinc-600 text-sm mt-1">Seeds receive warmup emails and generate positive signals</p>
        </div>
    </div>

        <!-- Quick Bulk Add Modal -->
        <div x-show="showQuickBulk" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showQuickBulk = false">
            <div class="w-full max-w-3xl glass rounded-2xl p-6 fade-in" @click.stop>
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h3 class="text-white font-semibold text-lg">Quick Bulk Add Seeds</h3>
                        <p class="text-zinc-500 text-xs mt-0.5">Paste one account per line: email + app password. No CSV needed.</p>
                    </div>
                    <button @click="showQuickBulk = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Default Provider</label>
                        <select x-model="bulkProvider" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                            <option value="google">Google (Gmail)</option>
                            <option value="microsoft">Microsoft (Outlook/365)</option>
                            <option value="zoho">Zoho Mail</option>
                            <option value="yahoo">Yahoo Mail</option>
                            <option value="hostinger">Hostinger Email</option>
                            <option value="titan">Titan Mail</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Daily Interaction Cap</label>
                        <input type="number" min="1" x-model="bulkCap" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                    </div>
                    <div class="flex items-end">
                        <div class="w-full p-3 rounded-xl bg-blue-500/10 border border-blue-500/20 text-[11px] text-blue-300">
                            Format: <span class="font-mono">email,app_password[,provider]</span>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Accounts</label>
                    <textarea x-model="bulkText" rows="10" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white font-mono"
                              placeholder="seed1@gmail.com,abcd efgh ijkl mnop\nseed2@gmail.com,qwer tyui opas dfgh\nseed3@outlook.com,zxcv bnmq asdf hjkl,microsoft"></textarea>
                </div>

                <div x-show="bulkResult" class="mb-4 p-3 rounded-xl" :class="bulkResult?.skipped > 0 ? 'bg-amber-500/10 border border-amber-500/20' : 'bg-emerald-500/10 border border-emerald-500/20'">
                    <p class="text-sm font-medium" :class="bulkResult?.skipped > 0 ? 'text-amber-400' : 'text-emerald-400'">
                        <span x-text="bulkResult?.imported || 0"></span> imported,
                        <span x-text="bulkResult?.skipped || 0"></span> skipped
                    </p>
                    <template x-if="bulkResult?.errors?.length">
                        <ul class="mt-2 space-y-0.5 max-h-28 overflow-y-auto">
                            <template x-for="err in bulkResult.errors" :key="err">
                                <li class="text-red-400/80 text-[11px]" x-text="err"></li>
                            </template>
                        </ul>
                    </template>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" @click="showQuickBulk = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Close</button>
                    <button type="button" @click="saveQuickBulk()" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium" :disabled="bulkLoading || !bulkText.trim()">
                        <span x-show="!bulkLoading">Bulk Add Now</span>
                        <span x-show="bulkLoading">Adding...</span>
                    </button>
                </div>
            </div>
        </div>

    <!-- CSV Import Modal -->
    <div x-show="showImport" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showImport = false">
        <div class="w-full max-w-lg glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <h3 class="text-white font-semibold text-lg">Import Seeds from CSV</h3>
                <button @click="showImport = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div class="mb-4 p-3 rounded-xl bg-blue-500/10 border border-blue-500/20">
                <p class="text-blue-400 text-xs font-medium mb-1">Required CSV columns:</p>
                <p class="text-blue-300/70 text-[11px] font-mono">email_address, smtp_host, smtp_port, smtp_username, smtp_password, imap_host, imap_port, imap_username, imap_password</p>
                <p class="text-zinc-500 text-[11px] mt-1">Optional: smtp_encryption, imap_encryption, provider, warmup_target_daily</p>
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

    <!-- Inbox Modal -->
    <div x-show="showInbox" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="closeInbox()">
        <div class="w-full max-w-6xl max-h-[90vh] overflow-y-auto glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex items-center justify-between mb-5">
                <div>
                    <h3 class="text-white font-semibold text-lg">Seed Inbox</h3>
                    <p class="text-zinc-500 text-xs mt-0.5" x-text="inboxMailbox?.email_address || 'Select a seed mailbox'"></p>
                </div>
                <button @click="closeInbox()" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>

            <div class="flex flex-wrap items-center gap-2 mb-4">
                <label class="text-xs text-zinc-400">Folder</label>
                <select x-model="inboxFolder" class="input-dark px-3 py-2 rounded-lg text-sm text-white min-w-[180px]">
                    <option value="INBOX">INBOX</option>
                    <option value="[Gmail]/All Mail">[Gmail]/All Mail</option>
                    <option value="[Gmail]/Spam">[Gmail]/Spam</option>
                    <option value="Junk">Junk</option>
                    <option value="Sent">Sent</option>
                </select>

                <label class="text-xs text-zinc-400 ml-2">Limit</label>
                <select x-model.number="inboxLimit" class="input-dark px-3 py-2 rounded-lg text-sm text-white">
                    <option :value="20">20</option>
                    <option :value="30">30</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>

                <button @click="fetchInbox()" class="px-3 py-2 rounded-lg text-sm text-cyan-200 bg-cyan-500/15 hover:bg-cyan-500/25 border border-cyan-500/30 disabled:opacity-60 disabled:cursor-not-allowed" :disabled="inboxLoading || !inboxMailbox">
                    <span x-show="!inboxLoading">Refresh</span>
                    <span x-show="inboxLoading">Loading...</span>
                </button>
            </div>

            <div x-show="inboxError" class="mb-4 p-3 rounded-xl bg-red-500/10 border border-red-500/25 text-red-300 text-sm" x-text="inboxError"></div>

            <div x-show="inboxLoading" class="text-center py-10">
                <i data-lucide="loader" class="w-5 h-5 text-zinc-500 animate-spin mx-auto"></i>
                <p class="text-zinc-500 text-sm mt-2">Fetching inbox...</p>
            </div>

            <div x-show="!inboxLoading && inboxData" class="space-y-3">
                <p class="text-xs text-zinc-500" x-text="'Showing ' + (inboxData.returned_messages || 0) + ' of ' + (inboxData.total_messages || 0) + ' messages in ' + (inboxData.folder || inboxFolder)"></p>

                <div class="overflow-x-auto border border-white/10 rounded-xl">
                    <table class="w-full min-w-[900px]">
                        <thead>
                            <tr class="border-b border-white/10 bg-white/[0.02]">
                                <th class="text-left px-4 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Date</th>
                                <th class="text-left px-4 py-2 text-[10px] uppercase tracking-wider text-zinc-500">From</th>
                                <th class="text-left px-4 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Subject</th>
                                <th class="text-left px-4 py-2 text-[10px] uppercase tracking-wider text-zinc-500">State</th>
                                <th class="text-left px-4 py-2 text-[10px] uppercase tracking-wider text-zinc-500">Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="m in (inboxData?.messages || [])" :key="String(m.uid) + '-' + String(m.message_no)">
                                <tr class="border-b border-white/[0.05] hover:bg-white/[0.02]">
                                    <td class="px-4 py-2 text-zinc-300 text-xs" x-text="formatInboxDate(m.date_iso, m.date)"></td>
                                    <td class="px-4 py-2 text-zinc-300 text-xs max-w-[260px] truncate" :title="m.from || ''" x-text="m.from || '—'"></td>
                                    <td class="px-4 py-2 text-zinc-200 text-xs max-w-[360px] truncate" :title="m.subject || ''" x-text="m.subject || '—'"></td>
                                    <td class="px-4 py-2 text-xs">
                                        <span class="px-2 py-0.5 rounded-full" :class="m.seen ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-300'" x-text="m.seen ? 'Seen' : 'Unread'"></span>
                                    </td>
                                    <td class="px-4 py-2 text-zinc-400 text-xs" x-text="formatBytes(m.size)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div x-show="(inboxData?.messages || []).length === 0" class="text-center py-8 text-zinc-500 text-sm">
                    No messages found in this folder.
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showModal = false">
        <div class="w-full max-w-xl max-h-[90vh] overflow-y-auto glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <h3 class="text-white font-semibold text-lg" x-text="editMode ? 'Edit Seed' : 'Add Seed Mailbox'"></h3>
                <button @click="showModal = false" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <form @submit.prevent="saveSeed()" class="space-y-4">

                <!-- Email + Provider -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Email Address *</label>
                        <input type="email" x-model="form.email_address" @change="syncUsername()" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" required :disabled="editMode">
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Provider *</label>
                        <select x-model="form.provider" @change="applyProviderDefaults()" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white">
                            <option value="">Custom / Other</option>
                            <option value="google">Google (Gmail)</option>
                            <option value="microsoft">Microsoft (Outlook/365)</option>
                            <option value="zoho">Zoho Mail</option>
                            <option value="yahoo">Yahoo Mail</option>
                            <option value="hostinger">Hostinger Email</option>
                            <option value="titan">Titan Mail</option>
                        </select>
                    </div>
                </div>

                <!-- App Password notice for Google -->
                <div x-show="form.provider === 'google'" class="flex items-start gap-2 p-3 rounded-xl bg-blue-500/10 border border-blue-500/20">
                    <i data-lucide="info" class="w-4 h-4 text-blue-400 flex-shrink-0 mt-0.5"></i>
                    <p class="text-blue-300 text-xs">Gmail requires an <strong>App Password</strong>, not your regular password. Enable 2-Step Verification then go to <span class="font-mono text-blue-200">myaccount.google.com → Security → App passwords</span> to generate one.</p>
                </div>

                <!-- Password (always shown) -->
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium" x-text="form.provider === 'google' || form.provider === 'microsoft' ? 'App Password *' : 'Password *'"></label>
                    <input type="password" x-model="form.smtp_password" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" :required="!editMode"
                           :placeholder="form.provider === 'google' ? '16-character app password' : form.provider === 'microsoft' ? 'Account or app password' : 'SMTP password'">
                    <p x-show="editMode" class="text-zinc-500 text-[10px] mt-1">Leave blank to keep existing password</p>
                </div>

                <!-- SMTP Section (auto-filled & read-only for known providers) -->
                <div class="border border-white/8 rounded-xl p-4 space-y-3">
                    <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
                        <i data-lucide="send" class="w-3.5 h-3.5"></i> SMTP (Outgoing)
                        <span x-show="form.provider === 'google' || form.provider === 'microsoft'" class="text-[10px] text-emerald-400 font-normal normal-case tracking-normal">Auto-filled</span>
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
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
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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

                <!-- IMAP Section (auto-filled & read-only for known providers) -->
                <div class="border border-white/8 rounded-xl p-4 space-y-3">
                    <p class="text-xs font-semibold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
                        <i data-lucide="inbox" class="w-3.5 h-3.5"></i> IMAP (Incoming — required for opens & replies)
                        <span x-show="form.provider === 'google' || form.provider === 'microsoft'" class="text-[10px] text-emerald-400 font-normal normal-case tracking-normal">Auto-filled</span>
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
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
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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

                <!-- Daily Cap -->
                <div>
                    <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Daily Interaction Cap</label>
                    <input type="number" x-model="form.daily_interaction_cap" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="20" min="1">
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="showModal = false" class="px-4 py-2.5 rounded-xl text-sm text-zinc-400 btn-ghost">Cancel</button>
                    <button type="submit" class="btn-primary px-5 py-2.5 rounded-xl text-sm text-white font-medium" :disabled="saving">
                        <span x-show="!saving" x-text="editMode ? 'Update Seed' : 'Add Seed'"></span>
                        <span x-show="saving">Saving...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Seed History Modal -->
    <div x-show="showDetail" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="closeDetail()">
        <div class="w-full max-w-6xl max-h-[90vh] overflow-y-auto glass rounded-2xl p-6 fade-in" @click.stop>
            <div class="flex items-center justify-between mb-5">
                <div>
                    <h3 class="text-white font-semibold text-lg">Seed Warmup History</h3>
                    <p class="text-zinc-500 text-xs mt-0.5" x-text="seedDetail?.seed?.email_address || 'Loading...'" ></p>
                </div>
                <button @click="closeDetail()" class="text-zinc-500 hover:text-white"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>

            <div x-show="detailLoading" class="text-center py-12">
                <i data-lucide="loader" class="w-5 h-5 text-zinc-500 animate-spin mx-auto"></i>
                <p class="text-zinc-500 text-sm mt-2">Loading seed history...</p>
            </div>

            <div x-show="!detailLoading && seedDetail" class="space-y-4">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <div class="glass rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-white" x-text="seedDetail.summary?.unique_senders ?? 0"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Senders</p>
                    </div>
                    <div class="glass rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-white" x-text="seedDetail.summary?.total_threads ?? 0"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Threads</p>
                    </div>
                    <div class="glass rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-emerald-400" x-text="seedDetail.summary?.active_threads ?? 0"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Active Threads</p>
                    </div>
                    <div class="glass rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-brand-400" x-text="seedDetail.summary?.messages_total ?? 0"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Messages</p>
                    </div>
                    <div class="glass rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-amber-400" x-text="seedDetail.summary?.interactions_30d ?? 0"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Interactions 30d</p>
                    </div>
                </div>

                <div x-show="!(seedDetail.by_sender || []).length" class="text-center py-10 text-zinc-500 text-sm">
                    No warmup history found for this seed yet.
                </div>

                <template x-for="group in (seedDetail.by_sender || [])" :key="group.sender_mailbox_id">
                    <div class="glass rounded-2xl overflow-hidden">
                        <div class="px-4 py-3 border-b border-white/5 flex items-center justify-between">
                            <div>
                                <p class="text-white font-medium text-sm" x-text="group.sender_email || 'Unknown sender'"></p>
                                <p class="text-zinc-500 text-[11px]" x-text="'Threads: ' + (group.threads_count || 0) + ' • Messages: ' + (group.messages_count || 0)"></p>
                            </div>
                            <div class="text-right">
                                <p class="text-zinc-400 text-[11px]">Last Interaction</p>
                                <p class="text-white text-xs" x-text="formatDateTime(group.last_interaction_at)"></p>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-white/[0.05]">
                                        <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Campaign</th>
                                        <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Thread</th>
                                        <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Direction</th>
                                        <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Subject</th>
                                        <th class="text-left px-4 py-2 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">Sent At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="msg in (group.messages || []).slice(0, 60)" :key="msg.thread_id + '-' + msg.sent_at + '-' + msg.direction">
                                        <tr class="border-b border-white/[0.03]">
                                            <td class="px-4 py-2 text-zinc-300" x-text="msg.campaign_name || '—'"></td>
                                            <td class="px-4 py-2 text-zinc-400" x-text="'#' + (msg.thread_id || '—')"></td>
                                            <td class="px-4 py-2 text-zinc-400" x-text="(msg.direction || '—').replace(/_/g, ' ')"></td>
                                            <td class="px-4 py-2 text-zinc-300 truncate max-w-[260px]" x-text="msg.subject || '—'"></td>
                                            <td class="px-4 py-2 text-zinc-500" x-text="formatDateTime(msg.sent_at)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>
            </div>
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
    hostinger: { smtp_host: 'smtp.hostinger.com',    smtp_port: 465, smtp_encryption: 'ssl', imap_host: 'imap.hostinger.com',       imap_port: 993, imap_encryption: 'ssl' },
    titan:     { smtp_host: 'smtp.titan.email',      smtp_port: 465, smtp_encryption: 'ssl', imap_host: 'imap.titan.email',         imap_port: 993, imap_encryption: 'ssl' },
};

function seedsPage() {
    return {
        seeds: [],
        showModal: false,
        showImport: false,
        showQuickBulk: false,
        showDetail: false,
        showInbox: false,
        inboxLoading: false,
        inboxError: '',
        inboxMailbox: null,
        inboxData: null,
        inboxFolder: 'INBOX',
        inboxLimit: 30,
        detailLoading: false,
        seedDetail: null,
        editMode: false,
        editId: null,
        saving: false,
        importFile: null,
        importing: false,
        importResult: null,
        bulkText: '',
        bulkProvider: 'google',
        bulkCap: 20,
        bulkLoading: false,
        bulkResult: null,
        checkingAllConnections: false,
        allConnectionCheckResult: null,
        testingSeedChecks: {},
        form: {},

        async init() { await this.load(); this.$nextTick(() => lucide.createIcons()); },
        async load() { try { this.seeds = await apiCall('/api/warmup/seed-mailboxes'); } catch(e) { this.seeds = []; } this.$nextTick(() => lucide.createIcons()); },

        async checkAllSeedConnections() {
            if (this.checkingAllConnections) return;

            if (!confirm('Run SMTP + IMAP checks for all seed mailboxes now?')) {
                return;
            }

            this.checkingAllConnections = true;
            this.allConnectionCheckResult = null;
            showToast('Checking all seed SMTP/IMAP connections. This may take some time...', 'info');

            try {
                const res = await apiCall('/api/warmup/seed-mailboxes/test-all-connections', 'POST', {
                    status: 'all',
                    limit: 500,
                });

                this.allConnectionCheckResult = res;
                await this.load();

                const issueCount = Number(res.with_issues || 0);
                const msg = `Checked ${res.total || 0} seeds: ${res.fully_healthy || 0} healthy, ${issueCount} with issues.`;
                showToast(msg, issueCount > 0 ? 'info' : 'success');
            } catch (e) {
                showToast('Bulk connection check failed: ' + e.message, 'error');
            } finally {
                this.checkingAllConnections = false;
                this.$nextTick(() => lucide.createIcons());
            }
        },

        resetForm() {
            this.form = { email_address: '', provider: '', smtp_host: '', smtp_port: 587, smtp_username: '', smtp_password: '', smtp_encryption: 'tls', imap_host: '', imap_port: 993, imap_username: '', imap_password: '', imap_encryption: 'ssl', daily_interaction_cap: 20 };
        },

        openQuickBulk() {
            this.showQuickBulk = true;
            this.bulkText = '';
            this.bulkProvider = 'google';
            this.bulkCap = 20;
            this.bulkResult = null;
            this.$nextTick(() => lucide.createIcons());
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

        editSeed(s) { this.editMode = true; this.editId = s.id; this.form = { ...s, smtp_password: '', imap_password: '' }; this.showModal = true; },
        async viewSeedHistory(seed) {
            this.showDetail = true;
            this.detailLoading = true;
            this.seedDetail = null;
            try {
                this.seedDetail = await apiCall(`/api/warmup/seed-mailboxes/${seed.id}/history`);
            } catch (e) {
                showToast('Failed to load seed history: ' + e.message, 'error');
            }
            this.detailLoading = false;
            this.$nextTick(() => lucide.createIcons());
        },
        closeDetail() {
            this.showDetail = false;
            this.seedDetail = null;
            this.detailLoading = false;
        },
        closeInbox() {
            this.showInbox = false;
            this.inboxLoading = false;
            this.inboxError = '';
            this.inboxMailbox = null;
            this.inboxData = null;
        },
        async openInbox(seed) {
            this.inboxMailbox = seed;
            this.inboxError = '';
            this.inboxData = null;
            this.showInbox = true;
            await this.fetchInbox();
        },
        async fetchInbox() {
            if (!this.inboxMailbox) return;

            this.inboxLoading = true;
            this.inboxError = '';

            const folder = encodeURIComponent(this.inboxFolder || 'INBOX');
            const limit = Math.max(1, Math.min(100, Number(this.inboxLimit || 30)));

            try {
                const res = await apiCall(`/api/warmup/seed-mailboxes/${this.inboxMailbox.id}/inbox?folder=${folder}&limit=${limit}`);
                if (!res.success) {
                    throw new Error(res.message || 'Failed to fetch inbox');
                }
                this.inboxData = res;
            } catch (e) {
                this.inboxError = e.message || 'Failed to fetch inbox';
            } finally {
                this.inboxLoading = false;
                this.$nextTick(() => lucide.createIcons());
            }
        },
        formatDateTime(iso) {
            if (!iso) return '—';
            return portalDateTime(iso);
        },
        formatInboxDate(dateIso, fallbackDate) {
            if (dateIso) {
                return portalDateTime(dateIso);
            }
            return fallbackDate || '—';
        },
        formatBytes(size) {
            const value = Number(size || 0);
            if (!Number.isFinite(value) || value <= 0) return '0 B';

            const units = ['B', 'KB', 'MB', 'GB'];
            let unit = 0;
            let bytes = value;

            while (bytes >= 1024 && unit < units.length - 1) {
                bytes /= 1024;
                unit++;
            }

            const precision = unit === 0 ? 0 : 1;
            return `${bytes.toFixed(precision)} ${units[unit]}`;
        },
        isSeedCheckBusy(seedId, channel) {
            return !!(this.testingSeedChecks[seedId] && this.testingSeedChecks[seedId][channel]);
        },
        setSeedCheckBusy(seedId, channel, value) {
            const previous = this.testingSeedChecks[seedId] || { smtp: false, imap: false };
            this.testingSeedChecks = {
                ...this.testingSeedChecks,
                [seedId]: {
                    ...previous,
                    [channel]: value,
                },
            };
        },
        async testSmtp(seed) {
            if (this.isSeedCheckBusy(seed.id, 'smtp')) return;

            const target = prompt(
                'Enter recipient email for a seed SMTP test message.\nLeave empty to only test SMTP connection.',
                seed?.email_address || ''
            );
            if (target === null) return;

            const email = (target || '').trim();
            const payload = email ? { test_email: email } : null;

            this.setSeedCheckBusy(seed.id, 'smtp', true);
            showToast(email ? 'Testing seed SMTP and sending test email...' : 'Testing seed SMTP connection...', 'info');

            try {
                const res = await apiCall(`/api/warmup/seed-mailboxes/${seed.id}/test-smtp`, 'POST', payload);
                const msg = res.message || (res.success ? 'Seed SMTP connected!' : 'Seed SMTP test failed');
                showToast(msg, res.success ? 'success' : 'error');
            } catch (e) {
                showToast('Seed SMTP test failed: ' + e.message, 'error');
            } finally {
                this.setSeedCheckBusy(seed.id, 'smtp', false);
                await this.load();
            }
        },
        async testImap(seed) {
            if (this.isSeedCheckBusy(seed.id, 'imap')) return;

            this.setSeedCheckBusy(seed.id, 'imap', true);
            showToast('Testing seed IMAP connection...', 'info');

            try {
                const res = await apiCall(`/api/warmup/seed-mailboxes/${seed.id}/test-imap`, 'POST');
                const msg = res.message || (res.success ? 'Seed IMAP connected!' : 'Seed IMAP test failed');
                showToast(msg, res.success ? 'success' : 'error');
            } catch (e) {
                showToast('Seed IMAP test failed: ' + e.message, 'error');
            } finally {
                this.setSeedCheckBusy(seed.id, 'imap', false);
                await this.load();
            }
        },
        async saveSeed() {
            this.saving = true;
            try {
                const data = { ...this.form };
                if (this.editMode && !data.smtp_password) delete data.smtp_password;
                if (this.editMode && !data.imap_password) delete data.imap_password;
                if (this.editMode) { await apiCall(`/api/warmup/seed-mailboxes/${this.editId}`, 'PUT', data); showToast('Seed updated'); }
                else { await apiCall('/api/warmup/seed-mailboxes', 'POST', data); showToast('Seed added'); }
                this.showModal = false; await this.load();
            } catch(e) { showToast('Error: ' + e.message, 'error'); }
            this.saving = false;
        },
        async deleteSeed(id) { if (!confirm('Delete this seed?')) return; try { await apiCall(`/api/warmup/seed-mailboxes/${id}`, 'DELETE'); showToast('Deleted'); await this.load(); } catch(e) { showToast('Error', 'error'); } },
        async togglePause(s) { const a = s.status === 'active' ? 'pause' : 'resume'; try { await apiCall(`/api/warmup/seed-mailboxes/${s.id}/${a}`, 'POST'); showToast(`Seed ${a}d`); await this.load(); } catch(e) { showToast('Error', 'error'); } },
        async saveQuickBulk() {
            if (!this.bulkText.trim()) return;

            this.bulkLoading = true;
            this.bulkResult = null;
            try {
                this.bulkResult = await apiCall('/api/warmup/seed-mailboxes/bulk', 'POST', {
                    bulk_text: this.bulkText,
                    provider: this.bulkProvider,
                    daily_interaction_cap: this.bulkCap,
                });

                showToast(`Imported ${this.bulkResult.imported || 0} seeds`, 'success');
                await this.load();
            } catch (e) {
                showToast('Bulk add failed: ' + e.message, 'error');
            }
            this.bulkLoading = false;
        },
        async uploadCsv() {
            if (!this.importFile) return;
            this.importing = true;
            this.importResult = null;
            try {
                const fd = new FormData();
                fd.append('csv_file', this.importFile);
                const res = await fetch('/api/warmup/import/seeds', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: fd
                });
                if (!res.ok) { const err = await res.json(); throw new Error(err.error || err.message || 'Import failed'); }
                this.importResult = await res.json();
                showToast(`Imported ${this.importResult.imported} seeds`, 'success');
                await this.load();
            } catch(e) { showToast('Error: ' + e.message, 'error'); }
            this.importing = false;
        }
    };
}
</script>
@endpush
