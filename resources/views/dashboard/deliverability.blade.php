@extends('layouts.app')

@section('title', 'Deliverability')
@section('page-title', 'Deliverability Command Center')
@section('page-description', 'Inbox placement, reputation, bounce intelligence & strategy optimizer')

@section('content')
<div x-data="deliverabilityPage()" x-init="init()" class="space-y-6 p-6 fade-in">

    <!-- Quick Actions Bar -->
    <div class="flex flex-wrap items-center gap-3">
        <button @click="runReputationScan()" :disabled="scanning" class="btn-primary px-4 py-2 rounded-xl text-sm font-medium text-white flex items-center gap-2">
            <i data-lucide="radar" class="w-4 h-4"></i>
            <span x-text="scanning ? 'Scanning...' : 'Reputation Scan'"></span>
        </button>
        <button @click="runStrategyAnalysis()" :disabled="analyzing" class="px-4 py-2 rounded-xl text-sm font-medium text-blue-400 border border-blue-500/30 hover:bg-blue-500/10 transition flex items-center gap-2">
            <i data-lucide="brain" class="w-4 h-4"></i>
            <span x-text="analyzing ? 'Analyzing...' : 'Strategy Analysis'"></span>
        </button>
        <button @click="showPlacementModal = true" class="px-4 py-2 rounded-xl text-sm font-medium text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/10 transition flex items-center gap-2">
            <i data-lucide="mail-search" class="w-4 h-4"></i>
            Run Placement Test
        </button>
        <div class="ml-auto text-xs text-zinc-500" x-show="lastScan" x-text="'Last scan: ' + lastScan"></div>
    </div>

    <!-- Overall Status Banner -->
    <div class="glass rounded-2xl p-5" :class="{
        'border-l-4 border-emerald-500': overview?.overall_status === 'healthy',
        'border-l-4 border-amber-500': overview?.overall_status === 'warning',
        'border-l-4 border-red-500': overview?.overall_status === 'critical'
    }">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center" :class="{
                    'bg-emerald-500/20': overview?.overall_status === 'healthy',
                    'bg-amber-500/20': overview?.overall_status === 'warning',
                    'bg-red-500/20': overview?.overall_status === 'critical'
                }">
                    <i :data-lucide="overview?.overall_status === 'healthy' ? 'shield-check' : overview?.overall_status === 'warning' ? 'alert-triangle' : 'shield-alert'" class="w-6 h-6" :class="{
                        'text-emerald-400': overview?.overall_status === 'healthy',
                        'text-amber-400': overview?.overall_status === 'warning',
                        'text-red-400': overview?.overall_status === 'critical'
                    }"></i>
                </div>
                <div>
                    <h3 class="text-white font-semibold text-lg capitalize" x-text="'Deliverability: ' + (overview?.overall_status || 'Loading...')"></h3>
                    <p class="text-zinc-400 text-sm">Real-time health across placement, reputation, and bounce signals</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Metrics Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-4">
        <div class="glass rounded-xl p-4 stat-card">
            <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium">Placement Score</p>
            <p class="text-2xl font-bold mt-1" :class="(overview?.placement?.avg_score || 0) >= 70 ? 'text-emerald-400' : (overview?.placement?.avg_score || 0) >= 40 ? 'text-amber-400' : 'text-red-400'" x-text="(overview?.placement?.avg_score || 0) + '%'"></p>
            <p class="text-[11px] text-zinc-500 mt-1" x-text="(overview?.placement?.total_tests || 0) + ' tests (7d)'"></p>
        </div>
        <div class="glass rounded-xl p-4 stat-card">
            <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium">Inbox Rate</p>
            <p class="text-emerald-400 text-2xl font-bold mt-1" x-text="overview?.placement?.total_inbox || 0"></p>
            <p class="text-[11px] text-zinc-500 mt-1">landed in inbox</p>
        </div>
        <div class="glass rounded-xl p-4 stat-card">
            <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium">Spam / Missing</p>
            <p class="text-red-400 text-2xl font-bold mt-1" x-text="(overview?.placement?.total_spam || 0) + ' / ' + (overview?.placement?.total_missing || 0)"></p>
            <p class="text-[11px] text-zinc-500 mt-1">spam &amp; not delivered</p>
        </div>
        <div class="glass rounded-xl p-4 stat-card">
            <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium">Total Bounces</p>
            <p class="text-2xl font-bold mt-1" :class="(overview?.bounces?.total || 0) > 10 ? 'text-red-400' : (overview?.bounces?.total || 0) > 3 ? 'text-amber-400' : 'text-emerald-400'" x-text="overview?.bounces?.total || 0"></p>
            <p class="text-[11px] text-zinc-500 mt-1">last 7 days</p>
        </div>
        <div class="glass rounded-xl p-4 stat-card">
            <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium">Domain Reputation</p>
            <p class="text-2xl font-bold mt-1" :class="(overview?.reputation?.domains?.avg_score || 0) >= 70 ? 'text-emerald-400' : (overview?.reputation?.domains?.avg_score || 0) >= 40 ? 'text-amber-400' : 'text-red-400'" x-text="(overview?.reputation?.domains?.avg_score || 0) + '/100'"></p>
            <p class="text-[11px] text-zinc-500 mt-1" x-text="(overview?.reputation?.domains?.critical || 0) + ' critical'"></p>
        </div>
        <div class="glass rounded-xl p-4 stat-card">
            <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium">DNS Changes</p>
            <p class="text-2xl font-bold mt-1" :class="(overview?.reputation?.dns_changes_7d || 0) > 0 ? 'text-amber-400' : 'text-emerald-400'" x-text="overview?.reputation?.dns_changes_7d || 0"></p>
            <p class="text-[11px] text-zinc-500 mt-1" x-text="(overview?.reputation?.recent_dns_alerts || 0) + ' degradations'"></p>
        </div>
    </div>

    <!-- Tabs -->
    <div class="flex border-b border-white/10 gap-1 overflow-x-auto">
        <template x-for="tab in tabs" :key="tab.id">
            <button @click="activeTab = tab.id"
                class="px-4 py-2.5 text-sm font-medium rounded-t-lg transition whitespace-nowrap flex items-center gap-2"
                :class="activeTab === tab.id ? 'text-white bg-white/10 border-b-2 border-blue-400' : 'text-zinc-500 hover:text-zinc-300'">
                <i :data-lucide="tab.icon" class="w-4 h-4"></i>
                <span x-text="tab.label"></span>
                <span x-show="tab.badge" class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold" :class="tab.badgeColor" x-text="tab.badge"></span>
            </button>
        </template>
    </div>

    <!-- Tab: Placement -->
    <div x-show="activeTab === 'placement'" x-cloak class="space-y-6">
        <!-- Sender Placement Table -->
        <div class="glass rounded-2xl overflow-hidden">
            <div class="p-5 border-b border-white/5 flex items-center justify-between">
                <div>
                    <h3 class="text-white font-semibold">Sender Inbox Placement</h3>
                    <p class="text-zinc-500 text-sm">Test results per sender mailbox</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Sender</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Score</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Inbox</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Spam</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Missing</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Last Test</th>
                            <th class="text-right px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="s in overview?.strategy?.sender_caps || []" :key="s.id">
                            <tr class="border-b border-white/5 hover:bg-white/[0.02] transition">
                                <td class="px-5 py-3">
                                    <span class="text-white text-sm font-medium" x-text="s.email"></span>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <span class="text-sm font-bold" :class="(s.placement || 0) >= 70 ? 'text-emerald-400' : (s.placement || 0) >= 40 ? 'text-amber-400' : s.placement !== null ? 'text-red-400' : 'text-zinc-500'" x-text="s.placement !== null ? s.placement + '%' : '--'"></span>
                                </td>
                                <td class="px-5 py-3 text-center text-emerald-400 text-sm" x-text="'—'"></td>
                                <td class="px-5 py-3 text-center text-red-400 text-sm" x-text="'—'"></td>
                                <td class="px-5 py-3 text-center text-zinc-400 text-sm" x-text="'—'"></td>
                                <td class="px-5 py-3 text-center text-zinc-500 text-xs" x-text="'—'"></td>
                                <td class="px-5 py-3 text-right">
                                    <button @click="runSinglePlacement(s.id)" class="text-xs text-blue-400 hover:text-blue-300 font-medium">Test</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div x-show="!overview?.strategy?.sender_caps?.length" class="p-10 text-center text-zinc-500 text-sm">
                No active senders found. Add senders to run placement tests.
            </div>
        </div>
    </div>

    <!-- Tab: Bounces -->
    <div x-show="activeTab === 'bounces'" x-cloak class="space-y-6">
        <!-- Bounce Type Breakdown -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <template x-for="[type, count] in Object.entries(overview?.bounces?.by_type || {})" :key="type">
                <div class="glass rounded-xl p-4 text-center">
                    <div class="w-8 h-8 mx-auto rounded-lg flex items-center justify-center mb-2" :class="{
                        'bg-red-500/20': type === 'hard',
                        'bg-amber-500/20': type === 'soft',
                        'bg-orange-500/20': type === 'policy',
                        'bg-blue-500/20': type === 'transient',
                        'bg-zinc-500/20': type === 'unknown'
                    }">
                        <i :data-lucide="type === 'hard' ? 'x-circle' : type === 'soft' ? 'clock' : type === 'policy' ? 'shield-off' : type === 'transient' ? 'wifi-off' : 'help-circle'" class="w-4 h-4" :class="{
                            'text-red-400': type === 'hard',
                            'text-amber-400': type === 'soft',
                            'text-orange-400': type === 'policy',
                            'text-blue-400': type === 'transient',
                            'text-zinc-400': type === 'unknown'
                        }"></i>
                    </div>
                    <p class="text-white text-xl font-bold" x-text="count"></p>
                    <p class="text-zinc-500 text-[11px] uppercase tracking-wider mt-1 capitalize" x-text="type"></p>
                </div>
            </template>
        </div>

        <!-- Root Cause Analysis -->
        <div class="glass rounded-2xl overflow-hidden">
            <div class="p-5 border-b border-white/5">
                <h3 class="text-white font-semibold">Root Cause Analysis</h3>
                <p class="text-zinc-500 text-sm">Bounce patterns grouped by provider and type (7 days)</p>
            </div>
            <div class="divide-y divide-white/5">
                <template x-for="cause in bounceData?.root_causes || []" :key="cause.provider + cause.bounce_type">
                    <div class="p-4 hover:bg-white/[0.02] transition">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center" :class="{
                                    'bg-red-500/20': cause.bounce_type === 'hard',
                                    'bg-amber-500/20': cause.bounce_type === 'soft',
                                    'bg-orange-500/20': cause.bounce_type === 'policy',
                                    'bg-blue-500/20': cause.bounce_type === 'transient',
                                    'bg-zinc-500/20': cause.bounce_type === 'unknown'
                                }">
                                    <span class="text-xs font-bold uppercase" :class="{
                                        'text-red-400': cause.bounce_type === 'hard',
                                        'text-amber-400': cause.bounce_type === 'soft',
                                        'text-orange-400': cause.bounce_type === 'policy',
                                        'text-blue-400': cause.bounce_type === 'transient',
                                        'text-zinc-400': cause.bounce_type === 'unknown'
                                    }" x-text="cause.provider?.substring(0, 2)?.toUpperCase()"></span>
                                </div>
                                <div>
                                    <span class="text-white text-sm font-medium capitalize" x-text="cause.provider"></span>
                                    <span class="text-zinc-500 text-xs ml-2 capitalize" x-text="cause.bounce_type"></span>
                                </div>
                            </div>
                            <span class="text-white font-bold text-sm" x-text="cause.count"></span>
                        </div>
                        <p class="text-zinc-400 text-sm ml-11" x-text="cause.recommendation"></p>
                        <div class="ml-11 mt-1 flex flex-wrap gap-1.5" x-show="cause.sample_codes?.length">
                            <template x-for="code in cause.sample_codes || []" :key="code">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-white/5 text-zinc-400" x-text="code"></span>
                            </template>
                        </div>
                    </div>
                </template>
                <div x-show="!bounceData?.root_causes?.length" class="p-10 text-center text-zinc-500 text-sm">
                    No bounces recorded in the last 7 days.
                </div>
            </div>
        </div>

        <!-- Top Offending Senders -->
        <div class="glass rounded-2xl overflow-hidden" x-show="overview?.bounces?.top_offending_senders?.length">
            <div class="p-5 border-b border-white/5">
                <h3 class="text-white font-semibold">Top Offending Senders</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Sender</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Total</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Hard</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="s in overview?.bounces?.top_offending_senders || []" :key="s.id">
                            <tr class="border-b border-white/5">
                                <td class="px-5 py-3 text-white text-sm" x-text="s.email"></td>
                                <td class="px-5 py-3 text-center text-amber-400 text-sm font-bold" x-text="s.total"></td>
                                <td class="px-5 py-3 text-center text-red-400 text-sm font-bold" x-text="s.hard"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Suppression Candidates -->
        <div class="glass rounded-2xl overflow-hidden" x-show="overview?.bounces?.suppression_candidates?.length">
            <div class="p-5 border-b border-white/5 flex items-center justify-between">
                <div>
                    <h3 class="text-white font-semibold">Suppression Candidates</h3>
                    <p class="text-zinc-500 text-sm">Emails with repeated hard bounces — consider suppressing</p>
                </div>
            </div>
            <div class="divide-y divide-white/5">
                <template x-for="c in overview?.bounces?.suppression_candidates || []" :key="c.email">
                    <div class="px-5 py-3 flex items-center justify-between hover:bg-white/[0.02] transition">
                        <div>
                            <span class="text-white text-sm" x-text="c.email"></span>
                            <span class="text-zinc-500 text-xs ml-2" x-text="c.bounces + ' bounces'"></span>
                        </div>
                        <button @click="suppressEmail(c.email)" class="text-xs text-red-400 hover:text-red-300 font-medium px-3 py-1 rounded-lg border border-red-500/30 hover:bg-red-500/10 transition">
                            Suppress
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- Tab: Reputation -->
    <div x-show="activeTab === 'reputation'" x-cloak class="space-y-6">
        <!-- Risk Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="glass rounded-xl p-4 border-l-4 border-emerald-500">
                <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium">Low Risk</p>
                <p class="text-emerald-400 text-3xl font-bold mt-1" x-text="(overview?.reputation?.domains?.low || 0) + (overview?.reputation?.senders?.low || 0)"></p>
                <p class="text-zinc-500 text-xs mt-1">domains + senders</p>
            </div>
            <div class="glass rounded-xl p-4 border-l-4 border-amber-500">
                <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium">Medium Risk</p>
                <p class="text-amber-400 text-3xl font-bold mt-1" x-text="(overview?.reputation?.domains?.medium || 0) + (overview?.reputation?.senders?.medium || 0)"></p>
                <p class="text-zinc-500 text-xs mt-1">need monitoring</p>
            </div>
            <div class="glass rounded-xl p-4 border-l-4 border-orange-500">
                <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium">High Risk</p>
                <p class="text-orange-400 text-3xl font-bold mt-1" x-text="(overview?.reputation?.domains?.high || 0) + (overview?.reputation?.senders?.high || 0)"></p>
                <p class="text-zinc-500 text-xs mt-1">action needed</p>
            </div>
            <div class="glass rounded-xl p-4 border-l-4 border-red-500">
                <p class="text-zinc-500 text-[11px] uppercase tracking-wider font-medium">Critical</p>
                <p class="text-red-400 text-3xl font-bold mt-1" x-text="(overview?.reputation?.domains?.critical || 0) + (overview?.reputation?.senders?.critical || 0)"></p>
                <p class="text-zinc-500 text-xs mt-1">urgent attention</p>
            </div>
        </div>

        <!-- Domain Reputation Detail -->
        <div class="glass rounded-2xl overflow-hidden">
            <div class="p-5 border-b border-white/5">
                <h3 class="text-white font-semibold">Domain Reputation Details</h3>
            </div>
            <div class="divide-y divide-white/5">
                <template x-for="domain in domainReputations" :key="domain.id">
                    <div class="p-4 hover:bg-white/[0.02] transition cursor-pointer" @click="viewDomainDetail(domain.id)">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center" :class="{
                                    'bg-emerald-500/20': domain.risk_level === 'low',
                                    'bg-amber-500/20': domain.risk_level === 'medium',
                                    'bg-orange-500/20': domain.risk_level === 'high',
                                    'bg-red-500/20': domain.risk_level === 'critical'
                                }">
                                    <i data-lucide="globe" class="w-5 h-5" :class="{
                                        'text-emerald-400': domain.risk_level === 'low',
                                        'text-amber-400': domain.risk_level === 'medium',
                                        'text-orange-400': domain.risk_level === 'high',
                                        'text-red-400': domain.risk_level === 'critical'
                                    }"></i>
                                </div>
                                <div>
                                    <p class="text-white font-medium" x-text="domain.name"></p>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold uppercase" :class="{
                                            'bg-emerald-500/20 text-emerald-400': domain.dns?.spf === 'pass',
                                            'bg-red-500/20 text-red-400': domain.dns?.spf !== 'pass'
                                        }">SPF</span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold uppercase" :class="{
                                            'bg-emerald-500/20 text-emerald-400': domain.dns?.dkim === 'pass',
                                            'bg-red-500/20 text-red-400': domain.dns?.dkim !== 'pass'
                                        }">DKIM</span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold uppercase" :class="{
                                            'bg-emerald-500/20 text-emerald-400': domain.dns?.dmarc === 'pass',
                                            'bg-red-500/20 text-red-400': domain.dns?.dmarc !== 'pass'
                                        }">DMARC</span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold uppercase" :class="{
                                            'bg-emerald-500/20 text-emerald-400': domain.dns?.mx === 'pass',
                                            'bg-red-500/20 text-red-400': domain.dns?.mx !== 'pass'
                                        }">MX</span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-2xl font-bold" :class="{
                                    'text-emerald-400': domain.reputation_score >= 75,
                                    'text-amber-400': domain.reputation_score >= 50 && domain.reputation_score < 75,
                                    'text-orange-400': domain.reputation_score >= 25 && domain.reputation_score < 50,
                                    'text-red-400': domain.reputation_score < 25
                                }" x-text="domain.reputation_score + '/100'"></p>
                                <span class="text-[11px] px-2 py-0.5 rounded-full uppercase font-bold" :class="{
                                    'bg-emerald-500/20 text-emerald-400': domain.risk_level === 'low',
                                    'bg-amber-500/20 text-amber-400': domain.risk_level === 'medium',
                                    'bg-orange-500/20 text-orange-400': domain.risk_level === 'high',
                                    'bg-red-500/20 text-red-400': domain.risk_level === 'critical'
                                }" x-text="domain.risk_level"></span>
                            </div>
                        </div>
                    </div>
                </template>
                <div x-show="!domainReputations.length" class="p-10 text-center text-zinc-500 text-sm">
                    No domains scored. Run a reputation scan to begin.
                </div>
            </div>
        </div>

        <!-- DNS Audit Log -->
        <div class="glass rounded-2xl overflow-hidden" x-show="dnsAuditLog.length">
            <div class="p-5 border-b border-white/5">
                <h3 class="text-white font-semibold">Recent DNS Changes</h3>
                <p class="text-zinc-500 text-sm">Authentication record changes detected</p>
            </div>
            <div class="divide-y divide-white/5">
                <template x-for="(entry, i) in dnsAuditLog" :key="i">
                    <div class="px-5 py-3 flex items-center gap-4 hover:bg-white/[0.02] transition">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center" :class="entry.new === 'pass' ? 'bg-emerald-500/20' : 'bg-red-500/20'">
                            <i :data-lucide="entry.new === 'pass' ? 'check-circle' : 'alert-circle'" class="w-4 h-4" :class="entry.new === 'pass' ? 'text-emerald-400' : 'text-red-400'"></i>
                        </div>
                        <div class="flex-1">
                            <span class="text-white text-sm font-medium" x-text="entry.record_type"></span>
                            <span class="text-zinc-500 text-xs ml-2" x-text="entry.previous + ' → ' + entry.new"></span>
                        </div>
                        <span class="text-zinc-500 text-xs" x-text="entry.date"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- Tab: Strategy -->
    <div x-show="activeTab === 'strategy'" x-cloak class="space-y-6">
        <!-- Recommendation Summary -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="glass rounded-xl p-4 text-center">
                <div class="w-8 h-8 mx-auto rounded-lg bg-emerald-500/20 flex items-center justify-center mb-2">
                    <i data-lucide="trending-up" class="w-4 h-4 text-emerald-400"></i>
                </div>
                <p class="text-emerald-400 text-xl font-bold" x-text="overview?.strategy?.todays_recommendations?.ramp_up || 0"></p>
                <p class="text-zinc-500 text-[11px] uppercase tracking-wider mt-1">Ramp Up</p>
            </div>
            <div class="glass rounded-xl p-4 text-center">
                <div class="w-8 h-8 mx-auto rounded-lg bg-blue-500/20 flex items-center justify-center mb-2">
                    <i data-lucide="minus" class="w-4 h-4 text-blue-400"></i>
                </div>
                <p class="text-blue-400 text-xl font-bold" x-text="overview?.strategy?.todays_recommendations?.maintain || 0"></p>
                <p class="text-zinc-500 text-[11px] uppercase tracking-wider mt-1">Maintain</p>
            </div>
            <div class="glass rounded-xl p-4 text-center">
                <div class="w-8 h-8 mx-auto rounded-lg bg-amber-500/20 flex items-center justify-center mb-2">
                    <i data-lucide="trending-down" class="w-4 h-4 text-amber-400"></i>
                </div>
                <p class="text-amber-400 text-xl font-bold" x-text="overview?.strategy?.todays_recommendations?.slow_down || 0"></p>
                <p class="text-zinc-500 text-[11px] uppercase tracking-wider mt-1">Slow Down</p>
            </div>
            <div class="glass rounded-xl p-4 text-center">
                <div class="w-8 h-8 mx-auto rounded-lg bg-red-500/20 flex items-center justify-center mb-2">
                    <i data-lucide="pause-circle" class="w-4 h-4 text-red-400"></i>
                </div>
                <p class="text-red-400 text-xl font-bold" x-text="overview?.strategy?.todays_recommendations?.pause || 0"></p>
                <p class="text-zinc-500 text-[11px] uppercase tracking-wider mt-1">Pause</p>
            </div>
            <div class="glass rounded-xl p-4 text-center">
                <div class="w-8 h-8 mx-auto rounded-lg bg-cyan-500/20 flex items-center justify-center mb-2">
                    <i data-lucide="play-circle" class="w-4 h-4 text-cyan-400"></i>
                </div>
                <p class="text-cyan-400 text-xl font-bold" x-text="overview?.strategy?.todays_recommendations?.resume || 0"></p>
                <p class="text-zinc-500 text-[11px] uppercase tracking-wider mt-1">Resume</p>
            </div>
        </div>

        <!-- Sender Strategy Table -->
        <div class="glass rounded-2xl overflow-hidden">
            <div class="p-5 border-b border-white/5 flex items-center justify-between">
                <div>
                    <h3 class="text-white font-semibold">Sending Strategy per Sender</h3>
                    <p class="text-zinc-500 text-sm">Current caps and adaptive recommendations</p>
                </div>
                <button @click="runStrategyAnalysis(true)" class="text-xs text-emerald-400 hover:text-emerald-300 font-medium px-3 py-1.5 rounded-lg border border-emerald-500/30 hover:bg-emerald-500/10 transition">
                    Auto-Apply All
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Sender</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Day</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Daily Cap</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Reputation</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Placement</th>
                            <th class="text-right px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Analyze</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="s in overview?.strategy?.sender_caps || []" :key="s.id">
                            <tr class="border-b border-white/5 hover:bg-white/[0.02] transition">
                                <td class="px-5 py-3 text-white text-sm font-medium" x-text="s.email"></td>
                                <td class="px-5 py-3 text-center text-zinc-400 text-sm" x-text="s.warmup_day || '—'"></td>
                                <td class="px-5 py-3 text-center">
                                    <span class="text-white text-sm font-bold" x-text="s.daily_cap"></span>
                                    <span class="text-zinc-500 text-xs">/day</span>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <span class="text-sm font-bold" :class="(s.reputation || 50) >= 70 ? 'text-emerald-400' : (s.reputation || 50) >= 40 ? 'text-amber-400' : 'text-red-400'" x-text="(s.reputation || '—')"></span>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <span class="text-sm font-bold" :class="(s.placement || 0) >= 70 ? 'text-emerald-400' : (s.placement || 0) >= 40 ? 'text-amber-400' : s.placement !== null ? 'text-red-400' : 'text-zinc-500'" x-text="s.placement !== null ? s.placement + '%' : '—'"></span>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <button @click="analyzeSender(s.id)" class="text-xs text-blue-400 hover:text-blue-300 font-medium">Analyze</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Strategy Changes -->
        <div class="glass rounded-2xl overflow-hidden" x-show="overview?.strategy?.recent_changes?.length">
            <div class="p-5 border-b border-white/5">
                <h3 class="text-white font-semibold">Recent Applied Changes</h3>
            </div>
            <div class="divide-y divide-white/5">
                <template x-for="(change, i) in overview?.strategy?.recent_changes || []" :key="i">
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center" :class="{
                                'bg-emerald-500/20': change.recommendation === 'ramp_up',
                                'bg-amber-500/20': change.recommendation === 'slow_down',
                                'bg-red-500/20': change.recommendation === 'pause',
                                'bg-cyan-500/20': change.recommendation === 'resume',
                                'bg-blue-500/20': change.recommendation === 'maintain'
                            }">
                                <i :data-lucide="change.recommendation === 'ramp_up' ? 'trending-up' : change.recommendation === 'slow_down' ? 'trending-down' : change.recommendation === 'pause' ? 'pause-circle' : 'play-circle'" class="w-4 h-4" :class="{
                                    'text-emerald-400': change.recommendation === 'ramp_up',
                                    'text-amber-400': change.recommendation === 'slow_down',
                                    'text-red-400': change.recommendation === 'pause',
                                    'text-cyan-400': change.recommendation === 'resume',
                                    'text-blue-400': change.recommendation === 'maintain'
                                }"></i>
                            </div>
                            <div>
                                <span class="text-white text-sm capitalize" x-text="change.recommendation.replace('_', ' ')"></span>
                                <span class="text-zinc-500 text-xs ml-2" x-text="change.old_cap + ' → ' + change.new_cap + '/day'"></span>
                            </div>
                        </div>
                        <span class="text-zinc-500 text-xs" x-text="change.date"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- Tab: Bounce Log -->
    <div x-show="activeTab === 'bounce_log'" x-cloak class="space-y-6">
        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-3">
            <select x-model="bounceFilter.type" @change="loadBounceLog()" class="px-3 py-2 rounded-xl text-sm bg-white/5 border border-white/10 text-zinc-300 focus:border-blue-500 outline-none">
                <option value="">All Types</option>
                <option value="hard">Hard</option>
                <option value="soft">Soft</option>
                <option value="policy">Policy</option>
                <option value="transient">Transient</option>
                <option value="unknown">Unknown</option>
            </select>
            <select x-model="bounceFilter.provider" @change="loadBounceLog()" class="px-3 py-2 rounded-xl text-sm bg-white/5 border border-white/10 text-zinc-300 focus:border-blue-500 outline-none">
                <option value="">All Providers</option>
                <option value="gmail">Gmail</option>
                <option value="outlook">Outlook</option>
                <option value="yahoo">Yahoo</option>
                <option value="other">Other</option>
            </select>
        </div>

        <div class="glass rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Sender</th>
                            <th class="text-left px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Recipient</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Type</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Code</th>
                            <th class="text-left px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Message</th>
                            <th class="text-center px-5 py-3 text-[11px] uppercase tracking-wider text-zinc-500 font-medium">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="b in bounceLogEntries" :key="b.id">
                            <tr class="border-b border-white/5 hover:bg-white/[0.02] transition">
                                <td class="px-5 py-3 text-white text-sm" x-text="b.sender"></td>
                                <td class="px-5 py-3 text-zinc-400 text-sm" x-text="b.recipient"></td>
                                <td class="px-5 py-3 text-center">
                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase" :class="{
                                        'bg-red-500/20 text-red-400': b.type === 'hard',
                                        'bg-amber-500/20 text-amber-400': b.type === 'soft',
                                        'bg-orange-500/20 text-orange-400': b.type === 'policy',
                                        'bg-blue-500/20 text-blue-400': b.type === 'transient',
                                        'bg-zinc-500/20 text-zinc-400': b.type === 'unknown'
                                    }" x-text="b.type"></span>
                                </td>
                                <td class="px-5 py-3 text-center text-zinc-400 text-xs font-mono" x-text="b.code || '—'"></td>
                                <td class="px-5 py-3 text-zinc-400 text-xs max-w-xs truncate" x-text="b.message"></td>
                                <td class="px-5 py-3 text-center text-zinc-500 text-xs" x-text="b.date"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div x-show="!bounceLogEntries.length" class="p-10 text-center text-zinc-500 text-sm">
                No bounce events found.
            </div>
        </div>
    </div>

    <!-- Placement Test Modal -->
    <div x-show="showPlacementModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.6); backdrop-filter: blur(4px);">
        <div class="glass rounded-2xl p-6 w-full max-w-md" @click.outside="showPlacementModal = false">
            <h3 class="text-white font-semibold text-lg mb-4">Run Placement Test</h3>
            <p class="text-zinc-400 text-sm mb-4">Select a sender to test inbox placement across all seed mailboxes.</p>
            <select x-model="selectedPlacementSender" class="w-full px-4 py-3 rounded-xl text-sm bg-white/5 border border-white/10 text-zinc-300 focus:border-blue-500 outline-none mb-4">
                <option value="">Select sender...</option>
                <template x-for="s in overview?.senders || []" :key="s.id">
                    <option :value="s.id" x-text="s.email"></option>
                </template>
            </select>
            <div class="flex gap-3 justify-end">
                <button @click="showPlacementModal = false" class="px-4 py-2 rounded-xl text-sm text-zinc-400 hover:text-white transition">Cancel</button>
                <button @click="runSinglePlacement(selectedPlacementSender); showPlacementModal = false" :disabled="!selectedPlacementSender" class="btn-primary px-4 py-2 rounded-xl text-sm font-medium text-white disabled:opacity-50">
                    Run Test
                </button>
            </div>
        </div>
    </div>

    <!-- Sender Analysis Modal -->
    <div x-show="showAnalysis" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.6); backdrop-filter: blur(4px);">
        <div class="glass rounded-2xl p-6 w-full max-w-lg" @click.outside="showAnalysis = false">
            <h3 class="text-white font-semibold text-lg mb-4">Strategy Analysis</h3>
            <div x-show="analysisResult" class="space-y-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center" :class="{
                        'bg-emerald-500/20': analysisResult?.recommendation === 'ramp_up',
                        'bg-blue-500/20': analysisResult?.recommendation === 'maintain',
                        'bg-amber-500/20': analysisResult?.recommendation === 'slow_down',
                        'bg-red-500/20': analysisResult?.recommendation === 'pause',
                        'bg-cyan-500/20': analysisResult?.recommendation === 'resume'
                    }">
                        <i :data-lucide="analysisResult?.recommendation === 'ramp_up' ? 'trending-up' : analysisResult?.recommendation === 'slow_down' ? 'trending-down' : 'minus'" class="w-5 h-5 text-white"></i>
                    </div>
                    <div>
                        <p class="text-white font-semibold capitalize" x-text="analysisResult?.recommendation?.replace('_', ' ')"></p>
                        <p class="text-zinc-400 text-sm" x-text="analysisResult?.current_cap + ' → ' + analysisResult?.recommended_cap + '/day'"></p>
                    </div>
                </div>
                <p class="text-zinc-400 text-sm" x-text="analysisResult?.reasoning"></p>

                <!-- Metrics Grid -->
                <div class="grid grid-cols-2 gap-3" x-show="analysisResult?.metrics">
                    <template x-for="[key, val] in Object.entries(analysisResult?.metrics || {}).filter(([k]) => ['reply_rate','open_rate','bounce_rate','spam_rate','placement_score','reputation_score'].includes(k))" :key="key">
                        <div class="p-3 rounded-xl bg-white/5">
                            <p class="text-zinc-500 text-[11px] uppercase tracking-wider" x-text="key.replace(/_/g, ' ')"></p>
                            <p class="text-white text-lg font-bold mt-0.5" x-text="typeof val === 'number' ? val + (key.includes('rate') ? '%' : '') : val ?? '—'"></p>
                        </div>
                    </template>
                </div>

                <div class="flex gap-3 justify-end mt-4">
                    <button @click="showAnalysis = false" class="px-4 py-2 rounded-xl text-sm text-zinc-400 hover:text-white transition">Close</button>
                    <button @click="applyStrategy(analysisResult?.sender_id); showAnalysis = false" class="btn-primary px-4 py-2 rounded-xl text-sm font-medium text-white" x-show="analysisResult?.recommendation !== 'maintain'">
                        Apply Recommendation
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function deliverabilityPage() {
    return {
        overview: null,
        bounceData: null,
        domainReputations: [],
        dnsAuditLog: [],
        bounceLogEntries: [],
        activeTab: 'placement',
        scanning: false,
        analyzing: false,
        lastScan: null,
        showPlacementModal: false,
        showAnalysis: false,
        selectedPlacementSender: '',
        analysisResult: null,
        bounceFilter: { type: '', provider: '' },

        tabs: [
            { id: 'placement', label: 'Placement', icon: 'mail-search', badge: null, badgeColor: '' },
            { id: 'bounces', label: 'Bounces', icon: 'alert-triangle', badge: null, badgeColor: '' },
            { id: 'reputation', label: 'Reputation', icon: 'shield', badge: null, badgeColor: '' },
            { id: 'strategy', label: 'Strategy', icon: 'brain', badge: null, badgeColor: '' },
            { id: 'bounce_log', label: 'Bounce Log', icon: 'list', badge: null, badgeColor: '' },
        ],

        async init() {
            await this.loadOverview();
            await this.loadBounceData();
            await this.loadDomainReputations();
            this.updateBadges();
            this.$nextTick(() => lucide.createIcons());
        },

        async loadOverview() {
            try {
                const res = await fetch('/api/warmup/deliverability/overview');
                this.overview = await res.json();
                this.lastScan = new Date().toLocaleTimeString();
            } catch (e) {
                console.error('Failed to load overview:', e);
            }
        },

        async loadBounceData() {
            try {
                const res = await fetch('/api/warmup/deliverability/bounces');
                this.bounceData = await res.json();
            } catch (e) {
                console.error('Failed to load bounce data:', e);
            }
        },

        async loadDomainReputations() {
            try {
                const res = await fetch('/api/warmup/dns-health');
                const data = await res.json();
                this.domainReputations = (data.domains || data || []).map(d => ({
                    id: d.id,
                    name: d.domain_name,
                    reputation_score: d.reputation_score || d.domain_health_score || 0,
                    risk_level: d.reputation_risk_level || 'low',
                    dns: {
                        spf: d.spf_status,
                        dkim: d.dkim_status,
                        dmarc: d.dmarc_status,
                        mx: d.mx_status,
                    }
                }));
            } catch (e) {
                console.error('Failed to load domain reputations:', e);
            }
        },

        async loadBounceLog() {
            try {
                let url = '/api/warmup/deliverability/bounce-log?';
                if (this.bounceFilter.type) url += 'bounce_type=' + this.bounceFilter.type + '&';
                if (this.bounceFilter.provider) url += 'provider=' + this.bounceFilter.provider + '&';
                const res = await fetch(url);
                const data = await res.json();
                this.bounceLogEntries = data.bounces || [];
            } catch (e) {
                console.error('Failed to load bounce log:', e);
            }
        },

        async runReputationScan() {
            this.scanning = true;
            try {
                await fetch('/api/warmup/deliverability/reputation/scan', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content } });
                await this.loadOverview();
                await this.loadDomainReputations();
                this.updateBadges();
            } catch (e) {
                console.error('Reputation scan failed:', e);
            }
            this.scanning = false;
            this.$nextTick(() => lucide.createIcons());
        },

        async runStrategyAnalysis(autoApply = false) {
            this.analyzing = true;
            try {
                await fetch('/api/warmup/deliverability/strategy/run?auto_apply=' + (autoApply ? '1' : '0'), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content }
                });
                await this.loadOverview();
                this.updateBadges();
            } catch (e) {
                console.error('Strategy analysis failed:', e);
            }
            this.analyzing = false;
            this.$nextTick(() => lucide.createIcons());
        },

        async runSinglePlacement(senderId) {
            if (!senderId) return;
            try {
                const res = await fetch('/api/warmup/deliverability/placement/test', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content,
                    },
                    body: JSON.stringify({ sender_mailbox_id: senderId }),
                });
                const data = await res.json();
                if (data.test) {
                    await this.loadOverview();
                    this.$nextTick(() => lucide.createIcons());
                }
            } catch (e) {
                console.error('Placement test failed:', e);
            }
        },

        async analyzeSender(senderId) {
            try {
                const res = await fetch('/api/warmup/deliverability/strategy/' + senderId + '/analyze');
                this.analysisResult = await res.json();
                this.analysisResult.sender_id = senderId;
                this.showAnalysis = true;
                this.$nextTick(() => lucide.createIcons());
            } catch (e) {
                console.error('Sender analysis failed:', e);
            }
        },

        async applyStrategy(senderId) {
            if (!senderId) return;
            try {
                await fetch('/api/warmup/deliverability/strategy/' + senderId + '/apply', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content }
                });
                await this.loadOverview();
                this.$nextTick(() => lucide.createIcons());
            } catch (e) {
                console.error('Apply strategy failed:', e);
            }
        },

        async viewDomainDetail(domainId) {
            try {
                const res = await fetch('/api/warmup/deliverability/reputation/domain/' + domainId);
                const data = await res.json();
                this.dnsAuditLog = data.dns_audit || [];
                this.$nextTick(() => lucide.createIcons());
            } catch (e) {
                console.error('Failed to load domain detail:', e);
            }
        },

        async suppressEmail(email) {
            try {
                await fetch('/api/warmup/deliverability/bounces/suppress', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content,
                    },
                    body: JSON.stringify({ email }),
                });
                await this.loadOverview();
                await this.loadBounceData();
            } catch (e) {
                console.error('Suppress failed:', e);
            }
        },

        updateBadges() {
            const bounces = this.overview?.bounces?.total || 0;
            const critical = (this.overview?.reputation?.domains?.critical || 0) + (this.overview?.reputation?.senders?.critical || 0);

            this.tabs[1].badge = bounces > 0 ? bounces : null;
            this.tabs[1].badgeColor = bounces > 5 ? 'bg-red-500/20 text-red-400' : 'bg-amber-500/20 text-amber-400';
            this.tabs[2].badge = critical > 0 ? critical : null;
            this.tabs[2].badgeColor = 'bg-red-500/20 text-red-400';
        }
    };
}
</script>

@endsection
