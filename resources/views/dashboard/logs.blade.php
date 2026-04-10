@extends('layouts.app')
@section('title', 'Event Logs')
@section('page-title', 'Event Logs')
@section('page-description', 'Warmup event execution history and performance')

@section('content')
<div x-data="logsPage()" x-init="init()">

    <!-- Filters -->
    <div class="glass rounded-2xl p-4 mb-6">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex-1 min-w-[200px]">
                <input type="text" x-model="filters.search" @input.debounce.300ms="load()" class="input-dark w-full px-3.5 py-2.5 rounded-xl text-sm text-white" placeholder="Search by event type, email...">
            </div>
            <div>
                <select x-model="filters.status" @change="load()" class="input-dark px-3.5 py-2.5 rounded-xl text-sm text-white">
                    <option value="">All Outcomes</option>
                    <option value="success">Success</option>
                    <option value="failure">Failure</option>
                    <option value="retry">Retry</option>
                    <option value="skipped">Skipped</option>
                </select>
            </div>
            <div>
                <select x-model="filters.event_type" @change="load()" class="input-dark px-3.5 py-2.5 rounded-xl text-sm text-white">
                    <option value="">All Types</option>
                    <option value="send_new_thread">New Thread</option>
                    <option value="reply">Reply</option>
                    <option value="open">Open</option>
                    <option value="mark_important">Mark Important</option>
                    <option value="rescue_from_spam">Spam Rescue</option>
                    <option value="move_to_inbox">Move to Inbox</option>
                </select>
            </div>
            <div>
                <input type="date" x-model="filters.date" @change="load()" class="input-dark px-3.5 py-2.5 rounded-xl text-sm text-white">
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <div class="glass rounded-xl p-3 text-center">
            <p class="text-lg font-bold text-white" x-text="stats.total ?? 0"></p>
            <p class="text-zinc-500 text-[10px] uppercase tracking-wider">Total</p>
        </div>
        <div class="glass rounded-xl p-3 text-center">
            <p class="text-lg font-bold text-emerald-400" x-text="stats.success ?? 0"></p>
            <p class="text-zinc-500 text-[10px] uppercase tracking-wider">Success</p>
        </div>
        <div class="glass rounded-xl p-3 text-center">
            <p class="text-lg font-bold text-red-400" x-text="stats.failure ?? 0"></p>
            <p class="text-zinc-500 text-[10px] uppercase tracking-wider">Failure</p>
        </div>
        <div class="glass rounded-xl p-3 text-center">
            <p class="text-lg font-bold text-amber-400" x-text="stats.retry ?? 0"></p>
            <p class="text-zinc-500 text-[10px] uppercase tracking-wider">Retry</p>
        </div>
        <div class="glass rounded-xl p-3 text-center">
            <p class="text-lg font-bold text-zinc-400" x-text="stats.skipped ?? 0"></p>
            <p class="text-zinc-500 text-[10px] uppercase tracking-wider">Skipped</p>
        </div>
    </div>

    <!-- Table -->
    <div class="glass rounded-2xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/5">
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Time</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Type</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Campaign</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Outcome</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Duration</th>
                    <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Details</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="log in logs" :key="log.id">
                    <tr class="table-row border-b border-white/[0.03]">
                        <td class="px-5 py-3">
                            <span class="text-sm text-zinc-300" x-text="formatTime(log.executed_at || log.scheduled_at)"></span>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded-md flex items-center justify-center" :class="typeIcon(log.event_type).bg">
                                    <i :data-lucide="typeIcon(log.event_type).icon" class="w-3 h-3" :class="typeIcon(log.event_type).color"></i>
                                </div>
                                <span class="text-sm text-white font-medium" x-text="log.event_type?.replaceAll('_', ' ')"></span>
                            </div>
                        </td>
                        <td class="px-5 py-3 text-sm text-zinc-400" x-text="log.event?.campaign?.campaign_name || '—'"></td>
                        <td class="px-5 py-3">
                            <span class="badge px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase"
                                  :class="outcomeColor(log.outcome)" x-text="log.outcome"></span>
                        </td>
                        <td class="px-5 py-3 text-sm text-zinc-500" x-text="log.execution_time_ms ? log.execution_time_ms + 'ms' : '—'"></td>
                        <td class="px-5 py-3 text-sm text-zinc-400/70 max-w-[200px] truncate" x-text="log.details || ''"></td>
                    </tr>
                </template>
            </tbody>
        </table>
        <div x-show="logs.length === 0" class="text-center py-16">
            <i data-lucide="file-text" class="w-7 h-7 text-zinc-700 mx-auto mb-2"></i>
            <p class="text-zinc-500 text-sm">No event logs found</p>
        </div>

        <!-- Pagination -->
        <div class="flex items-center justify-between px-5 py-3 border-t border-white/5" x-show="totalPages > 1">
            <span class="text-zinc-500 text-xs" x-text="'Page ' + page + ' of ' + totalPages"></span>
            <div class="flex gap-1">
                <button @click="page = Math.max(1, page - 1); load()" :disabled="page <= 1" class="px-3 py-1.5 rounded-lg text-xs text-zinc-400 bg-white/[0.03] hover:bg-white/[0.06] disabled:opacity-30">Prev</button>
                <button @click="page++; load()" :disabled="page >= totalPages" class="px-3 py-1.5 rounded-lg text-xs text-zinc-400 bg-white/[0.03] hover:bg-white/[0.06] disabled:opacity-30">Next</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function logsPage() {
    return {
        logs: [], stats: {}, page: 1, totalPages: 1,
        filters: { search: '', status: '', event_type: '', date: '' },
        async init() { await this.load(); this.$nextTick(() => lucide.createIcons()); },
        async load() {
            try {
                const params = new URLSearchParams();
                params.set('page', this.page);
                if (this.filters.status) params.set('status', this.filters.status);
                if (this.filters.event_type) params.set('event_type', this.filters.event_type);
                if (this.filters.date) params.set('date', this.filters.date);
                const res = await apiCall(`/api/warmup/event-logs?${params}`);
                this.logs = res.data ?? res ?? [];
                this.totalPages = res.last_page ?? 1;
                this.stats = res.stats ?? {};
            } catch(e) { this.logs = []; }
            this.$nextTick(() => lucide.createIcons());
        },
        formatTime(t) { if (!t) return '—'; const d = new Date(t); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); },
        typeIcon(type) {
            const map = {
                send_new_thread: { icon: 'send', bg: 'bg-brand-500/15', color: 'text-brand-400' },
                reply: { icon: 'reply', bg: 'bg-emerald-500/15', color: 'text-emerald-400' },
                open: { icon: 'eye', bg: 'bg-blue-500/15', color: 'text-blue-400' },
                mark_important: { icon: 'star', bg: 'bg-amber-500/15', color: 'text-amber-400' },
                rescue_from_spam: { icon: 'shield', bg: 'bg-red-500/15', color: 'text-red-400' },
                move_to_inbox: { icon: 'inbox', bg: 'bg-teal-500/15', color: 'text-teal-400' },
            };
            return map[type] || { icon: 'zap', bg: 'bg-zinc-500/15', color: 'text-zinc-400' };
        },
        outcomeColor(o) { return { success: 'bg-emerald-500/15 text-emerald-400', failure: 'bg-red-500/15 text-red-400', retry: 'bg-amber-500/15 text-amber-400', skipped: 'bg-zinc-500/15 text-zinc-400' }[o] || 'bg-zinc-500/15 text-zinc-400'; }
    };
}
</script>
@endpush
