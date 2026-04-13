@extends('layouts.app')
@section('title', 'Campaign Detail')
@section('page-title', 'Campaign Detail')
@section('page-description', 'Analytics, events, and thread timeline for this campaign')

@section('content')
<div x-data="campaignDetail()" x-init="init()">

    <!-- Loading -->
    <div x-show="loading" class="text-center py-20">
        <div class="w-12 h-12 rounded-xl glass flex items-center justify-center mx-auto mb-3 shimmer">
            <i data-lucide="loader" class="w-5 h-5 text-zinc-500 animate-spin"></i>
        </div>
        <p class="text-zinc-500 text-sm">Loading campaign data...</p>
    </div>

    <div x-show="!loading" x-cloak>

        <!-- Back + Campaign Header -->
        <div class="flex items-center gap-3 mb-6">
            <a href="/campaigns" class="btn-ghost p-2 rounded-lg text-zinc-500 hover:text-white">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div class="flex-1">
                <h3 class="text-white font-semibold text-lg" x-text="campaign.campaign_name || 'Campaign'"></h3>
                <p class="text-zinc-500 text-xs" x-text="'Day ' + (campaign.current_day_number || 1) + ' • ' + (campaign.status || '—')"></p>
            </div>
            <span class="badge px-3 py-1.5 rounded-full text-xs font-semibold uppercase tracking-widest"
                  :class="statusBadge(campaign.status)" x-text="campaign.status"></span>
        </div>

        <!-- Stat Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="stat-card glass rounded-2xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-9 h-9 rounded-lg gradient-brand flex items-center justify-center"><i data-lucide="send" class="w-4 h-4 text-white"></i></div>
                    <span class="text-zinc-500 text-xs font-medium">Total Events</span>
                </div>
                <p class="text-2xl font-bold text-white" x-text="report.total_events ?? 0"></p>
            </div>
            <div class="stat-card glass rounded-2xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-9 h-9 rounded-lg gradient-success flex items-center justify-center"><i data-lucide="check-circle" class="w-4 h-4 text-white"></i></div>
                    <span class="text-zinc-500 text-xs font-medium">Completed</span>
                </div>
                <p class="text-2xl font-bold text-emerald-400" x-text="report.completed_events ?? 0"></p>
            </div>
            <div class="stat-card glass rounded-2xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-9 h-9 rounded-lg gradient-danger flex items-center justify-center"><i data-lucide="alert-triangle" class="w-4 h-4 text-white"></i></div>
                    <span class="text-zinc-500 text-xs font-medium">Failed</span>
                </div>
                <p class="text-2xl font-bold text-red-400" x-text="report.failed_events ?? 0"></p>
            </div>
            <div class="stat-card glass rounded-2xl p-4">
                <div class="flex items-center gap-3 mb-2">
                    <div class="w-9 h-9 rounded-lg gradient-info flex items-center justify-center"><i data-lucide="percent" class="w-4 h-4 text-white"></i></div>
                    <span class="text-zinc-500 text-xs font-medium">Success Rate</span>
                </div>
                <p class="text-2xl font-bold text-blue-400" x-text="(report.success_rate ?? 0) + '%'"></p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 mb-6 border-b border-white/5 pb-px">
            <template x-for="t in ['overview','schedule','threads','events']" :key="t">
                <button @click="tab = t; if(t === 'schedule') loadSchedule(); if(t === 'events') loadEvents();" class="px-4 py-2.5 text-sm font-medium rounded-t-lg transition"
                        :class="tab === t ? 'text-white bg-white/5 border-b-2 border-brand-500' : 'text-zinc-500 hover:text-zinc-300'"
                        x-text="t.charAt(0).toUpperCase() + t.slice(1)"></button>
            </template>
        </div>

        <!-- Overview Tab -->
        <div x-show="tab === 'overview'">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Activity Chart -->
                <div class="glass rounded-2xl p-5">
                    <h4 class="text-white font-semibold text-sm mb-4">Daily Activity</h4>
                    <div class="h-64">
                        <canvas id="activityChart"></canvas>
                    </div>
                </div>

                <!-- Campaign Info -->
                <div class="glass rounded-2xl p-5">
                    <h4 class="text-white font-semibold text-sm mb-4">Campaign Info</h4>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Sender</span>
                            <span class="text-white text-sm font-medium" x-text="campaign.sender_mailbox?.email_address || '—'"></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Profile</span>
                            <span class="text-white text-sm font-medium" x-text="campaign.profile?.profile_name || '—'"></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Current Stage</span>
                            <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                  :class="stageBadge(campaign.current_stage)"
                                  x-text="(campaign.current_stage || '—').replace('_',' ')"></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Day</span>
                            <span class="text-white text-sm font-medium" x-text="(campaign.current_day_number || 1) + ' / ' + profileTotalDays()"></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Time Window</span>
                            <span class="text-white text-sm font-medium" x-text="(campaign.time_window_start || '08:00') + ' — ' + (campaign.time_window_end || '22:00')"></span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-white/5">
                            <span class="text-zinc-500 text-sm">Threads</span>
                            <span class="text-white text-sm font-medium" x-text="report.total_threads ?? 0"></span>
                        </div>
                        <div class="flex items-center justify-between py-2">
                            <span class="text-zinc-500 text-sm">Pending Events</span>
                            <span class="text-amber-400 text-sm font-medium" x-text="report.pending_events ?? 0"></span>
                        </div>
                    </div>
                </div>

                <!-- Readiness Score -->
                <div class="glass rounded-2xl p-5">
                    <h4 class="text-white font-semibold text-sm mb-4">Sender Readiness</h4>
                    <div class="flex items-center gap-6">
                        <div class="relative w-24 h-24">
                            <svg class="transform -rotate-90 w-24 h-24" viewBox="0 0 36 36">
                                <path d="M18 2.0845a15.9155 15.9155 0 010 31.831 15.9155 15.9155 0 010-31.831" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="3"/>
                                <path d="M18 2.0845a15.9155 15.9155 0 010 31.831 15.9155 15.9155 0 010-31.831" fill="none"
                                      :stroke="readiness.overall >= 70 ? '#22c55e' : readiness.overall >= 40 ? '#f59e0b' : '#ef4444'"
                                      stroke-width="3" stroke-linecap="round"
                                      :stroke-dasharray="readiness.overall + ', 100'"/>
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <span class="text-xl font-bold text-white" x-text="readiness.overall ?? 0"></span>
                            </div>
                        </div>
                        <div class="flex-1 space-y-2 text-sm">
                            <template x-for="(val, key) in readiness.breakdown || {}" :key="key">
                                <div class="flex items-center justify-between">
                                    <span class="text-zinc-500 capitalize" x-text="key.replace(/_/g,' ')"></span>
                                    <span class="text-white font-medium" x-text="val"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Event Type Breakdown -->
                <div class="glass rounded-2xl p-5">
                    <h4 class="text-white font-semibold text-sm mb-4">Event Breakdown</h4>
                    <div class="h-64">
                        <canvas id="breakdownChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule Tab — Live Countdown Timeline -->
        <div x-show="tab === 'schedule'">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h4 class="text-white font-semibold text-sm">Schedule Timeline</h4>
                    <p class="text-zinc-500 text-xs mt-0.5">Exact send/reply times with live countdowns. Pick a campaign day/date below.</p>
                </div>
                <div class="flex items-center gap-2">
                    <select x-model="selectedScheduleDate" @change="loadSchedule(); loadEvents();" class="input-dark px-3 py-1.5 rounded-lg text-xs text-white">
                        <option :value="todayDateString()">Today</option>
                        <template x-for="rec in dailyRecords" :key="rec.id">
                            <option :value="rec.plan_date" x-text="'Day ' + rec.warmup_day_number + ' - ' + rec.plan_date"></option>
                        </template>
                    </select>
                    <button @click="loadSchedule()" class="px-3 py-1.5 rounded-lg text-xs font-medium text-brand-400 bg-brand-500/10 hover:bg-brand-500/20 transition flex items-center gap-1.5">
                        <i data-lucide="refresh-cw" class="w-3 h-3"></i> Refresh
                    </button>
                </div>
            </div>

            <!-- Day-wise planner records -->
            <div class="glass rounded-2xl overflow-hidden mb-5">
                <div class="px-5 py-3 border-b border-white/5 flex items-center justify-between">
                    <h5 class="text-white text-sm font-semibold">Daily Plan Records</h5>
                    <span class="text-zinc-500 text-[11px]" x-text="dailyRecords.length + ' day record(s)'"></span>
                </div>

                <div x-show="dailyRecords.length === 0" class="px-5 py-8 text-center text-zinc-500 text-sm">
                    No day records yet. Run planner to create day-by-day planning history.
                </div>

                <div x-show="dailyRecords.length > 0" class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-white/[0.05]">
                                <th class="text-left px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Day</th>
                                <th class="text-left px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Date</th>
                                <th class="text-left px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Stage</th>
                                <th class="text-right px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">New</th>
                                <th class="text-right px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Replies</th>
                                <th class="text-right px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">New + Replies</th>
                                <th class="text-right px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Total Budget</th>
                                <th class="text-right px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Eligible Seeds</th>
                                <th class="text-left px-5 py-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="rec in dailyRecords" :key="rec.id">
                                <tr class="border-b border-white/[0.03] hover:bg-white/[0.02] cursor-pointer" @click="selectedScheduleDate = rec.plan_date; loadSchedule(); loadEvents();">
                                    <td class="px-5 py-3 text-sm text-white font-medium" x-text="'Day ' + rec.warmup_day_number"></td>
                                    <td class="px-5 py-3 text-sm text-zinc-300" x-text="rec.plan_date"></td>
                                    <td class="px-5 py-3 text-sm text-zinc-400" x-text="(rec.warmup_stage || '—').replace('_',' ')"></td>
                                    <td class="px-5 py-3 text-sm text-right text-zinc-300" x-text="rec.new_thread_target"></td>
                                    <td class="px-5 py-3 text-sm text-right text-zinc-300" x-text="rec.reply_target"></td>
                                    <td class="px-5 py-3 text-sm text-right text-brand-300 font-semibold" x-text="rec.planned_new_plus_replies"></td>
                                    <td class="px-5 py-3 text-sm text-right text-zinc-300" x-text="rec.total_action_budget"></td>
                                    <td class="px-5 py-3 text-sm text-right text-zinc-400" x-text="rec.eligible_seed_count"></td>
                                    <td class="px-5 py-3">
                                        <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                              :class="rec.status === 'executing' ? 'bg-brand-500/15 text-brand-400' : rec.status === 'planned' ? 'bg-amber-500/15 text-amber-400' : rec.status === 'completed' ? 'bg-emerald-500/15 text-emerald-400' : 'bg-zinc-500/15 text-zinc-400'"
                                              x-text="rec.status"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div x-show="scheduleLoading" class="text-center py-12">
                <i data-lucide="loader" class="w-5 h-5 text-zinc-500 animate-spin mx-auto"></i>
                <p class="text-zinc-500 text-xs mt-2">Loading schedule...</p>
            </div>

            <div x-show="!scheduleLoading && scheduleEvents.length === 0" class="text-center py-16">
                <div class="w-14 h-14 rounded-2xl glass flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="calendar-x" class="w-6 h-6 text-zinc-600"></i>
                </div>
                <p class="text-zinc-400 font-medium text-sm">No events scheduled for today</p>
                <p class="text-zinc-600 text-xs mt-1">Run the Daily Planner from System Health to generate today's schedule.</p>
            </div>

            <!-- Schedule Timeline -->
            <div x-show="!scheduleLoading && scheduleEvents.length > 0" class="space-y-0">
                <!-- Summary cards -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                    <div class="glass rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-white" x-text="scheduleEvents.length"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Total Today</p>
                    </div>
                    <div class="glass rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-amber-400" x-text="scheduleEvents.filter(e => e.status === 'pending').length"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Pending</p>
                    </div>
                    <div class="glass rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-emerald-400" x-text="scheduleEvents.filter(e => e.status === 'completed').length"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Completed</p>
                    </div>
                    <div class="glass rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-red-400" x-text="scheduleEvents.filter(e => e.status === 'failed' || e.status === 'final_failed').length"></p>
                        <p class="text-[10px] text-zinc-500 uppercase">Failed</p>
                    </div>
                </div>

                <!-- Timeline list -->
                <div class="glass rounded-2xl overflow-hidden">
                    <template x-for="(ev, idx) in scheduleEvents" :key="ev.id">
                        <div class="flex items-center gap-4 px-5 py-3.5 border-b border-white/[0.03] hover:bg-white/[0.02] transition"
                             :class="ev.status === 'completed' ? 'opacity-60' : ''">
                            
                            <!-- Timeline dot -->
                            <div class="flex flex-col items-center gap-1 w-6">
                                <div class="w-3 h-3 rounded-full border-2"
                                     :class="ev.status === 'completed' ? 'bg-emerald-500 border-emerald-500' : ev.status === 'pending' ? 'bg-transparent border-amber-400 animate-pulse' : ev.status === 'executing' ? 'bg-brand-500 border-brand-500 animate-pulse' : 'bg-red-500 border-red-500'"></div>
                            </div>

                            <!-- Event icon -->
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                                 :class="eventIcon(ev.event_type).bg">
                                <i :data-lucide="eventIcon(ev.event_type).icon" class="w-4 h-4" :class="eventIcon(ev.event_type).color"></i>
                            </div>

                            <!-- Event info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-white text-sm font-medium" x-text="formatEventType(ev.event_type)"></span>
                                    <span class="badge px-1.5 py-0.5 rounded text-[9px]"
                                          :class="ev.status === 'completed' ? 'bg-emerald-500/15 text-emerald-400' : ev.status === 'pending' ? 'bg-amber-500/15 text-amber-400' : ev.status === 'executing' ? 'bg-brand-500/15 text-brand-400' : 'bg-red-500/15 text-red-400'"
                                          x-text="ev.status"></span>
                                </div>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="text-zinc-500 text-xs" x-text="ev.sender_email ? (ev.sender_email + ' → ' + (ev.seed_email || '—')) : '—'"></span>
                                </div>
                                <p class="text-zinc-600 text-[10px] truncate mt-0.5" x-show="ev.subject" x-text="'Subject: ' + ev.subject"></p>
                                <p class="text-red-400/70 text-[10px] truncate mt-0.5" x-show="ev.failure_reason" x-text="ev.failure_reason"></p>
                            </div>

                            <!-- Time + Countdown -->
                            <div class="text-right shrink-0 min-w-[120px]">
                                <p class="text-white text-sm font-mono font-medium" x-text="formatTime(ev.scheduled_at)"></p>
                                <template x-if="ev.status === 'pending'">
                                    <p class="text-xs font-mono mt-0.5"
                                       :class="getCountdown(ev.scheduled_at).isPast ? 'text-amber-400' : 'text-brand-400'"
                                       x-text="getCountdown(ev.scheduled_at).text"></p>
                                </template>
                                <template x-if="ev.status === 'completed'">
                                    <p class="text-emerald-500/60 text-[10px] mt-0.5" x-text="'Done ' + formatTime(ev.executed_at)"></p>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Threads Tab -->
        <div x-show="tab === 'threads'">
            <div class="glass rounded-2xl overflow-hidden">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Thread</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Seed</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Status</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Messages</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Subject</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="th in campaign.threads || []" :key="th.id">
                            <tr class="table-row border-b border-white/[0.03]">
                                <td class="px-5 py-3 text-sm text-white font-medium" x-text="'#' + th.id"></td>
                                <td class="px-5 py-3 text-sm text-zinc-400" x-text="th.seed_mailbox?.email_address || th.seedMailbox?.email_address || '—'"></td>
                                <td class="px-5 py-3">
                                    <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                          :class="th.thread_status === 'active' ? 'bg-emerald-500/15 text-emerald-400' : th.thread_status === 'closed' ? 'bg-brand-500/15 text-brand-400' : th.thread_status === 'closing' ? 'bg-amber-500/15 text-amber-400' : 'bg-zinc-500/15 text-zinc-400'"
                                          x-text="th.thread_status"></span>
                                </td>
                                <td class="px-5 py-3 text-sm text-zinc-400" x-text="(th.actual_message_count || 0) + ' / ' + (th.planned_message_count || '—')"></td>
                                <td class="px-5 py-3 text-sm text-zinc-400 truncate max-w-[180px]" x-text="th.subject_line || '—'"></td>
                                <td class="px-5 py-3 text-sm text-zinc-500" x-text="new Date(th.created_at).toLocaleDateString()"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="!campaign.threads?.length" class="text-center py-12">
                    <p class="text-zinc-500 text-sm">No threads yet</p>
                </div>
            </div>
        </div>

        <!-- Events Tab -->
        <div x-show="tab === 'events'">
            <div class="glass rounded-2xl overflow-hidden">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">ID</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Type</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Status</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Sender → Seed</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Subject</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Scheduled</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Executed</th>
                            <th class="text-left px-5 py-3.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="ev in events" :key="ev.id">
                            <tr class="table-row border-b border-white/[0.03]">
                                <td class="px-5 py-3 text-sm text-white font-medium" x-text="'#' + ev.id"></td>
                                <td class="px-5 py-3">
                                    <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                          :class="eventTypeBadge(ev.event_type)"
                                          x-text="(ev.event_type || '').replace(/_/g,' ')"></span>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="badge px-2 py-0.5 rounded-full text-[10px]"
                                          :class="ev.status === 'completed' ? 'bg-emerald-500/15 text-emerald-400' : ev.status === 'final_failed' || ev.status === 'failed' ? 'bg-red-500/15 text-red-400' : ev.status === 'pending' ? 'bg-amber-500/15 text-amber-400' : ev.status === 'executing' ? 'bg-brand-500/15 text-brand-400' : 'bg-zinc-500/15 text-zinc-400'"
                                          x-text="ev.status"></span>
                                </td>
                                <td class="px-5 py-3 text-sm text-zinc-400" x-text="(ev.sender_email || '—') + ' → ' + (ev.seed_email || '—')"></td>
                                <td class="px-5 py-3 text-sm text-zinc-400 truncate max-w-[220px]" x-text="ev.subject || '—'"></td>
                                <td class="px-5 py-3 text-sm text-zinc-400" x-text="ev.scheduled_at ? new Date(ev.scheduled_at).toLocaleString() : '—'"></td>
                                <td class="px-5 py-3 text-sm text-zinc-400" x-text="ev.executed_at ? new Date(ev.executed_at).toLocaleString() : '—'"></td>
                                <td class="px-5 py-3 text-sm text-red-400/80 truncate max-w-[200px]" x-text="ev.failure_reason || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div x-show="events.length === 0" class="text-center py-12">
                    <p class="text-zinc-500 text-sm">No events found</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function campaignDetail() {
    return {
        loading: true, campaign: {}, report: {}, readiness: {}, events: [], tab: 'overview',
        actChart: null, breakdownChart: null,
        scheduleEvents: [], scheduleLoading: false, scheduleInterval: null, countdownInterval: null,
        serverTimeDiff: 0,
        dailyRecords: [],
        selectedScheduleDate: null,

        get campaignId() {
            return window.location.pathname.split('/').filter(Boolean).pop();
        },

        statusBadge(s) {
            return { active: 'bg-emerald-500/15 text-emerald-400', paused: 'bg-amber-500/15 text-amber-400', draft: 'bg-zinc-500/15 text-zinc-400', stopped: 'bg-red-500/15 text-red-400', completed: 'bg-brand-500/15 text-brand-400' }[s] || 'bg-zinc-500/15 text-zinc-400';
        },

        stageBadge(stage) {
            const map = {
                ramp_up: 'bg-brand-500/15 text-brand-400',
                plateau: 'bg-emerald-500/15 text-emerald-400',
                maintenance: 'bg-amber-500/15 text-amber-400',
                initial_trust: 'bg-brand-500/15 text-brand-400',
                controlled_expansion: 'bg-blue-500/15 text-blue-400',
                behavioral_maturity: 'bg-emerald-500/15 text-emerald-400',
                readiness: 'bg-amber-500/15 text-amber-400',
            };
            return map[stage] || 'bg-zinc-500/15 text-zinc-400';
        },

        profileTotalDays() {
            const dr = this.campaign?.profile?.day_rules || {};
            const count = Object.keys(dr).length;
            return count || this.campaign?.planned_duration_days || '—';
        },

        todayDateString() {
            const d = new Date();
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        },

        eventTypeBadge(type) {
            if (type === 'sender_send_initial' || type === 'sender_reply') return 'bg-brand-500/15 text-brand-400';
            if (type === 'seed_open_email' || type === 'seed_reply') return 'bg-emerald-500/15 text-emerald-400';
            if (type === 'thread_close') return 'bg-zinc-500/15 text-zinc-400';
            return 'bg-amber-500/15 text-amber-400';
        },

        async init() {
            try {
                const data = await apiCall(`/api/warmup/campaigns/${this.campaignId}`);
                this.campaign = data.campaign || {};
                this.report = data.report || {};
                this.readiness = data.readiness || {};
                this.dailyRecords = data.daily_records || [];

                const defaultDate = this.dailyRecords?.[0]?.plan_date || this.todayDateString();
                this.selectedScheduleDate = defaultDate;

                await this.loadEvents();

            } catch(e) { showToast('Failed to load campaign: ' + e.message, 'error'); }
            this.loading = false;
            this.$nextTick(() => {
                lucide.createIcons();
                this.renderCharts();
            });
        },

        renderCharts() {
            // Activity chart - daily data
            const actCtx = document.getElementById('activityChart')?.getContext('2d');
            if (actCtx) {
                const dailyData = this.buildDailyData();
                this.actChart = new Chart(actCtx, {
                    type: 'bar',
                    data: {
                        labels: dailyData.labels,
                        datasets: [
                            { label: 'Sent', data: dailyData.sent, backgroundColor: 'rgba(99,102,241,0.7)', borderRadius: 4 },
                            { label: 'Completed', data: dailyData.completed, backgroundColor: 'rgba(34,197,94,0.7)', borderRadius: 4 },
                            { label: 'Failed', data: dailyData.failed, backgroundColor: 'rgba(239,68,68,0.7)', borderRadius: 4 },
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#a1a1aa', font: { size: 11 } } } }, scales: { x: { ticks: { color: '#71717a', font: { size: 10 } }, grid: { display: false } }, y: { ticks: { color: '#71717a', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.04)' } } } }
                });
            }

            // Breakdown doughnut
            const bCtx = document.getElementById('breakdownChart')?.getContext('2d');
            if (bCtx) {
                const types = this.countByType();
                this.breakdownChart = new Chart(bCtx, {
                    type: 'doughnut',
                    data: {
                        labels: Object.keys(types).map(k => k.replace(/_/g, ' ')),
                        datasets: [{ data: Object.values(types), backgroundColor: ['#6366f1','#22c55e','#f59e0b','#ef4444','#3b82f6','#8b5cf6'], borderWidth: 0 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { color: '#a1a1aa', font: { size: 11 }, padding: 16 } } } }
                });
            }
        },

        buildDailyData() {
            const map = {};
            (this.events || []).forEach(ev => {
                const d = ev.scheduled_at ? ev.scheduled_at.substring(0, 10) : (ev.created_at || '').substring(0, 10);
                if (!d) return;
                if (!map[d]) map[d] = { sent: 0, completed: 0, failed: 0 };
                map[d].sent++;
                if (ev.status === 'completed') map[d].completed++;
                if (ev.status === 'failed') map[d].failed++;
            });
            const sorted = Object.keys(map).sort();
            return {
                labels: sorted.map(d => d.substring(5)),
                sent: sorted.map(d => map[d].sent),
                completed: sorted.map(d => map[d].completed),
                failed: sorted.map(d => map[d].failed),
            };
        },

        countByType() {
            const counts = {};
            (this.events || []).forEach(ev => {
                const t = ev.event_type || 'unknown';
                counts[t] = (counts[t] || 0) + 1;
            });
            return counts;
        },

        // ─── Schedule Tab Methods ───

        async loadSchedule() {
            this.scheduleLoading = true;
            try {
                const date = this.selectedScheduleDate ? `?date=${encodeURIComponent(this.selectedScheduleDate)}` : '';
                const data = await apiCall(`/api/warmup/campaigns/${this.campaignId}/schedule${date}`);
                this.scheduleEvents = data.events || [];
                // Sync server time offset for accurate countdowns
                if (data.server_time) {
                    this.serverTimeDiff = new Date(data.server_time).getTime() - Date.now();
                }
            } catch(e) {
                showToast('Failed to load schedule: ' + e.message, 'error');
                this.scheduleEvents = [];
            }
            this.scheduleLoading = false;
            this.$nextTick(() => lucide.createIcons());

            // Auto-refresh schedule every 30 seconds
            if (this.scheduleInterval) clearInterval(this.scheduleInterval);
            this.scheduleInterval = setInterval(() => {
                if (this.tab === 'schedule') this.loadSchedule();
            }, 30000);
        },

        async loadEvents() {
            try {
                const date = this.selectedScheduleDate ? `?date=${encodeURIComponent(this.selectedScheduleDate)}` : '';
                const data = await apiCall(`/api/warmup/campaigns/${this.campaignId}/events${date}`);
                this.events = data.events || [];
            } catch (e) {
                this.events = [];
                showToast('Failed to load campaign events: ' + e.message, 'error');
            }
        },

        serverNow() {
            return new Date(Date.now() + this.serverTimeDiff);
        },

        formatTime(isoStr) {
            if (!isoStr) return '—';
            const d = new Date(isoStr);
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
        },

        getCountdown(isoStr) {
            if (!isoStr) return { text: '—', isPast: false };
            const target = new Date(isoStr).getTime();
            const now = this.serverNow().getTime();
            const diff = target - now;

            if (diff <= 0) {
                const pastSec = Math.abs(Math.floor(diff / 1000));
                if (pastSec < 60) return { text: `Overdue ${pastSec}s`, isPast: true };
                if (pastSec < 3600) return { text: `Overdue ${Math.floor(pastSec / 60)}m`, isPast: true };
                return { text: `Overdue ${Math.floor(pastSec / 3600)}h ${Math.floor((pastSec % 3600) / 60)}m`, isPast: true };
            }

            const h = Math.floor(diff / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            if (h > 0) return { text: `in ${h}h ${m}m ${s}s`, isPast: false };
            if (m > 0) return { text: `in ${m}m ${s}s`, isPast: false };
            return { text: `in ${s}s`, isPast: false };
        },

        formatEventType(type) {
            const map = {
                'sender_send_initial': '📤 Sender → Send Email',
                'seed_open_email': '📬 Seed → Open Email',
                'seed_reply': '💬 Seed → Reply',
                'sender_reply': '📤 Sender → Reply',
                'seed_mark_important': '⭐ Seed → Mark Important',
                'seed_star_message': '⭐ Seed → Star Message',
                'seed_remove_from_spam': '🛡️ Seed → Rescue from Spam',
                'thread_close': '🔒 Close Thread',
            };
            return map[type] || type.replace(/_/g, ' ');
        },

        eventIcon(type) {
            const icons = {
                'sender_send_initial': { icon: 'send', bg: 'bg-brand-500/15', color: 'text-brand-400' },
                'seed_open_email':     { icon: 'mail-open', bg: 'bg-emerald-500/15', color: 'text-emerald-400' },
                'seed_reply':          { icon: 'reply', bg: 'bg-blue-500/15', color: 'text-blue-400' },
                'sender_reply':        { icon: 'reply-all', bg: 'bg-brand-500/15', color: 'text-brand-400' },
                'seed_mark_important': { icon: 'star', bg: 'bg-amber-500/15', color: 'text-amber-400' },
                'seed_star_message':   { icon: 'star', bg: 'bg-amber-500/15', color: 'text-amber-400' },
                'seed_remove_from_spam': { icon: 'shield-check', bg: 'bg-emerald-500/15', color: 'text-emerald-400' },
                'thread_close':        { icon: 'lock', bg: 'bg-zinc-500/15', color: 'text-zinc-400' },
            };
            return icons[type] || { icon: 'circle', bg: 'bg-zinc-500/15', color: 'text-zinc-400' };
        }
    };
}
</script>
@endpush
