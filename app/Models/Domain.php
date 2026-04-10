<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    protected $fillable = [
        'domain_name', 'spf_status', 'dkim_status', 'dmarc_status', 'mx_status',
        'dns_last_checked_at', 'domain_health_score', 'readiness_score',
        'daily_domain_cap', 'daily_growth_cap', 'max_active_warming_mailboxes',
        'maintenance_mode', 'status',
        'reputation_risk_level', 'reputation_score', 'last_reputation_scan_at',
        'total_bounces_7d', 'total_sends_7d',
    ];

    protected $casts = [
        'dns_last_checked_at' => 'datetime',
        'maintenance_mode' => 'boolean',
        'last_reputation_scan_at' => 'datetime',
    ];

    public function senderMailboxes(): HasMany
    {
        return $this->hasMany(SenderMailbox::class);
    }

    public function warmupCampaigns(): HasMany
    {
        return $this->hasMany(WarmupCampaign::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function healthLogs(): HasMany
    {
        return $this->hasMany(DomainHealthLog::class);
    }

    public function dnsAuditLogs(): HasMany
    {
        return $this->hasMany(DnsAuditLog::class);
    }

    public function reputationScores(): HasMany
    {
        return $this->hasMany(ReputationScore::class);
    }

    public function activeSenderCount(): int
    {
        return $this->senderMailboxes()->where('status', 'active')->where('is_warmup_enabled', true)->count();
    }

    public function isDnsHealthy(): bool
    {
        return $this->spf_status === 'pass'
            && $this->dkim_status === 'pass'
            && $this->dmarc_status === 'pass'
            && $this->mx_status === 'pass';
    }
}
