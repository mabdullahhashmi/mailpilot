@extends('layouts.app')
@section('title', 'Settings')
@section('page-title', 'Settings')
@section('page-description', 'System configuration, account, and security settings')

@section('content')
<div x-data="settingsPage()" x-init="init()">

    <!-- Tabs -->
    <div class="flex items-center gap-1 p-1 rounded-xl glass mb-8 w-fit">
        <template x-for="tab in tabs" :key="tab.id">
            <button @click="activeTab = tab.id"
                    class="px-4 py-2 rounded-lg text-sm font-medium transition-all"
                    :class="activeTab === tab.id ? 'bg-brand-500/20 text-brand-400 shadow' : 'text-zinc-500 hover:text-zinc-300 hover:bg-white/5'">
                <span x-text="tab.label"></span>
            </button>
        </template>
    </div>

    <!-- Account Tab -->
    <div x-show="activeTab === 'account'" x-transition class="space-y-6">
        <div class="glass rounded-2xl p-6">
            <h3 class="text-white font-semibold text-base mb-1">Profile Information</h3>
            <p class="text-zinc-500 text-xs mb-6">Update your account name and email address</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 max-w-2xl">
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Name</label>
                    <input type="text" x-model="profile.name" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="Your Name">
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Email</label>
                    <input type="email" x-model="profile.email" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="admin@example.com">
                </div>
            </div>
            <button @click="saveProfile()" class="btn-primary mt-5 px-6 py-2.5 rounded-xl text-white text-sm font-semibold">
                Save Profile
            </button>
        </div>

        <div class="glass rounded-2xl p-6">
            <h3 class="text-white font-semibold text-base mb-1">Change Password</h3>
            <p class="text-zinc-500 text-xs mb-6">Use a strong, unique password with 8+ characters</p>

            <div class="space-y-4 max-w-md">
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Current Password</label>
                    <input type="password" x-model="passwords.current_password" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="••••••••">
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">New Password</label>
                    <input type="password" x-model="passwords.new_password" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="••••••••">
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Confirm New Password</label>
                    <input type="password" x-model="passwords.new_password_confirmation" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="••••••••">
                </div>
            </div>
            <button @click="changePassword()" class="btn-primary mt-5 px-6 py-2.5 rounded-xl text-white text-sm font-semibold">
                Change Password
            </button>
        </div>
    </div>

    <!-- Warmup Engine Tab -->
    <div x-show="activeTab === 'engine'" x-transition class="space-y-6">
        <div class="glass rounded-2xl p-6">
            <h3 class="text-white font-semibold text-base mb-1">Warmup Engine Settings</h3>
            <p class="text-zinc-500 text-xs mb-6">Configure global warmup behavior and limits</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 max-w-3xl">
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Max Events Per Batch</label>
                    <input type="number" x-model="engineSettings.max_events_per_batch" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="20">
                    <p class="text-zinc-600 text-[10px] mt-1">Events processed per scheduler run</p>
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Stale Lock Timeout (min)</label>
                    <input type="number" x-model="engineSettings.stale_lock_timeout_minutes" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="5">
                    <p class="text-zinc-600 text-[10px] mt-1">Max time before a locked event is released</p>
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Default Working Hours Start</label>
                    <input type="time" x-model="engineSettings.default_working_hours_start" class="input-dark w-full px-4 py-3 rounded-xl text-sm">
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Default Working Hours End</label>
                    <input type="time" x-model="engineSettings.default_working_hours_end" class="input-dark w-full px-4 py-3 rounded-xl text-sm">
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Default Domain Daily Cap</label>
                    <input type="number" x-model="engineSettings.default_domain_daily_cap" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="50">
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Max Retry Attempts</label>
                    <input type="number" x-model="engineSettings.max_retry_attempts" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="3">
                </div>
            </div>
            <button @click="saveEngineSettings()" class="btn-primary mt-5 px-6 py-2.5 rounded-xl text-white text-sm font-semibold">
                Save Engine Settings
            </button>
        </div>
    </div>

    <!-- DNS & Health Tab -->
    <div x-show="activeTab === 'health'" x-transition class="space-y-6">
        <div class="glass rounded-2xl p-6">
            <h3 class="text-white font-semibold text-base mb-1">Health Monitoring</h3>
            <p class="text-zinc-500 text-xs mb-6">Thresholds for automated health scoring and alerts</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 max-w-3xl">
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Bounce Rate Threshold (%)</label>
                    <input type="number" x-model="healthSettings.bounce_rate_threshold" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="5" step="0.1">
                    <p class="text-zinc-600 text-[10px] mt-1">Auto-pause sender if bounce rate exceeds this</p>
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Spam Rate Threshold (%)</label>
                    <input type="number" x-model="healthSettings.spam_rate_threshold" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="2" step="0.1">
                    <p class="text-zinc-600 text-[10px] mt-1">Auto-pause sender if spam rate exceeds this</p>
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">Min Health Score for Active</label>
                    <input type="number" x-model="healthSettings.min_health_score" class="input-dark w-full px-4 py-3 rounded-xl text-sm" placeholder="40" min="0" max="100">
                </div>
                <div>
                    <label class="block text-zinc-400 text-xs font-semibold tracking-wider uppercase mb-2">DNS Check Frequency</label>
                    <select x-model="healthSettings.dns_check_frequency" class="input-dark w-full px-4 py-3 rounded-xl text-sm">
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="biweekly">Bi-weekly</option>
                    </select>
                </div>
            </div>
            <button @click="saveHealthSettings()" class="btn-primary mt-5 px-6 py-2.5 rounded-xl text-white text-sm font-semibold">
                Save Health Settings
            </button>
        </div>
    </div>

    <!-- System Tab -->
    <div x-show="activeTab === 'system'" x-transition class="space-y-6">
        <div class="glass rounded-2xl p-6">
            <h3 class="text-white font-semibold text-base mb-1">System Information</h3>
            <p class="text-zinc-500 text-xs mb-6">Server environment and application details</p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5">
                    <p class="text-zinc-500 text-xs font-semibold uppercase tracking-wider mb-1">Laravel Version</p>
                    <p class="text-white text-sm font-medium">{{ app()->version() }}</p>
                </div>
                <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5">
                    <p class="text-zinc-500 text-xs font-semibold uppercase tracking-wider mb-1">PHP Version</p>
                    <p class="text-white text-sm font-medium">{{ PHP_VERSION }}</p>
                </div>
                <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5">
                    <p class="text-zinc-500 text-xs font-semibold uppercase tracking-wider mb-1">Environment</p>
                    <p class="text-white text-sm font-medium">{{ app()->environment() }}</p>
                </div>
                <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5">
                    <p class="text-zinc-500 text-xs font-semibold uppercase tracking-wider mb-1">Timezone</p>
                    <p class="text-white text-sm font-medium">{{ config('app.timezone') }}</p>
                </div>
                <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5">
                    <p class="text-zinc-500 text-xs font-semibold uppercase tracking-wider mb-1">Queue Driver</p>
                    <p class="text-white text-sm font-medium">{{ config('queue.default') }}</p>
                </div>
                <div class="p-4 rounded-xl bg-white/[0.03] border border-white/5">
                    <p class="text-zinc-500 text-xs font-semibold uppercase tracking-wider mb-1">IMAP Extension</p>
                    <p class="text-sm font-medium {{ extension_loaded('imap') ? 'text-emerald-400' : 'text-red-400' }}">
                        {{ extension_loaded('imap') ? 'Enabled' : 'Disabled' }}
                    </p>
                </div>
            </div>
        </div>

        <div class="glass rounded-2xl p-6">
            <h3 class="text-white font-semibold text-base mb-1">Cron Status</h3>
            <p class="text-zinc-500 text-xs mb-6">Last execution times for scheduled tasks</p>

            <div class="space-y-3 max-w-3xl">
                <template x-for="job in cronJobs" :key="job.name">
                    <div class="flex items-center justify-between p-4 rounded-xl bg-white/[0.03] border border-white/5">
                        <div class="flex items-center gap-3">
                            <div class="w-2 h-2 rounded-full" :class="job.ok ? 'bg-emerald-400' : 'bg-zinc-600'"></div>
                            <div>
                                <p class="text-white text-sm font-medium" x-text="job.name"></p>
                                <p class="text-zinc-600 text-[10px]" x-text="job.schedule"></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-zinc-400 text-xs" x-text="job.lastRun || 'Never'"></p>
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
function settingsPage() {
    return {
        activeTab: 'account',
        tabs: [
            { id: 'account', label: 'Account' },
            { id: 'engine', label: 'Warmup Engine' },
            { id: 'health', label: 'DNS & Health' },
            { id: 'system', label: 'System' },
        ],
        profile: { name: '', email: '' },
        passwords: { current_password: '', new_password: '', new_password_confirmation: '' },
        engineSettings: {
            max_events_per_batch: '20',
            stale_lock_timeout_minutes: '5',
            default_working_hours_start: '08:00',
            default_working_hours_end: '18:00',
            default_domain_daily_cap: '50',
            max_retry_attempts: '3',
        },
        healthSettings: {
            bounce_rate_threshold: '5',
            spam_rate_threshold: '2',
            min_health_score: '40',
            dns_check_frequency: 'weekly',
        },
        cronJobs: [
            { name: 'Daily Planner', schedule: 'Every day at 06:00', ok: false, lastRun: null },
            { name: 'Event Processor', schedule: 'Every 2 minutes', ok: false, lastRun: null },
            { name: 'Health Updates', schedule: 'Every day at 23:55', ok: false, lastRun: null },
            { name: 'DNS Checks', schedule: 'Monday at 03:00', ok: false, lastRun: null },
        ],

        async init() {
            try {
                const data = await apiCall('/api/warmup/settings');
                this.profile = data.user || this.profile;

                const s = data.settings || {};
                if (s.engine) {
                    Object.keys(this.engineSettings).forEach(k => {
                        if (s.engine[k] !== undefined) this.engineSettings[k] = s.engine[k];
                    });
                }
                if (s.health) {
                    Object.keys(this.healthSettings).forEach(k => {
                        if (s.health[k] !== undefined) this.healthSettings[k] = s.health[k];
                    });
                }

                // Load cron status
                this.loadCronStatus(s);
            } catch (e) {
                console.error('Settings load error:', e);
            }
            this.$nextTick(() => lucide.createIcons());
        },

        loadCronStatus(s) {
            const plannerRun = s.cron?.last_planner_run;
            const schedulerRun = s.cron?.last_scheduler_run;
            const healthRun = s.cron?.last_health_run;
            const dnsRun = s.cron?.last_dns_run;

            if (plannerRun) { this.cronJobs[0].ok = true; this.cronJobs[0].lastRun = plannerRun; }
            if (schedulerRun) { this.cronJobs[1].ok = true; this.cronJobs[1].lastRun = schedulerRun; }
            if (healthRun) { this.cronJobs[2].ok = true; this.cronJobs[2].lastRun = healthRun; }
            if (dnsRun) { this.cronJobs[3].ok = true; this.cronJobs[3].lastRun = dnsRun; }
        },

        async saveProfile() {
            try {
                await apiCall('/api/warmup/settings/profile', 'PUT', this.profile);
                showToast('Profile updated');
            } catch (e) { showToast('Error: ' + e.message, 'error'); }
        },

        async changePassword() {
            if (this.passwords.new_password !== this.passwords.new_password_confirmation) {
                showToast('Passwords do not match', 'error');
                return;
            }
            try {
                await apiCall('/api/warmup/settings/password', 'PUT', this.passwords);
                this.passwords = { current_password: '', new_password: '', new_password_confirmation: '' };
                showToast('Password changed');
            } catch (e) { showToast('Error: ' + e.message, 'error'); }
        },

        async saveEngineSettings() {
            try {
                const items = Object.entries(this.engineSettings).map(([key, value]) => ({
                    key, value: String(value), group: 'engine'
                }));
                await apiCall('/api/warmup/settings', 'PUT', { settings: items });
                showToast('Engine settings saved');
            } catch (e) { showToast('Error: ' + e.message, 'error'); }
        },

        async saveHealthSettings() {
            try {
                const items = Object.entries(this.healthSettings).map(([key, value]) => ({
                    key, value: String(value), group: 'health'
                }));
                await apiCall('/api/warmup/settings', 'PUT', { settings: items });
                showToast('Health settings saved');
            } catch (e) { showToast('Error: ' + e.message, 'error'); }
        }
    };
}
</script>
@endsection
