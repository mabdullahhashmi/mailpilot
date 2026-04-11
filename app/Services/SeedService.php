<?php

namespace App\Services;

use App\Models\SeedMailbox;
use Illuminate\Support\Facades\Crypt;

class SeedService
{
    public function create(array $data): SeedMailbox
    {
        // Map frontend field names to model columns
        if (isset($data['daily_interaction_cap'])) {
            $data['daily_total_interaction_cap'] = $data['daily_interaction_cap'];
            unset($data['daily_interaction_cap']);
        }
        if (isset($data['provider'])) {
            $data['provider_type'] = $this->mapProviderType($data['provider']);
            unset($data['provider']);
        }

        if (isset($data['smtp_password'])) {
            $data['smtp_password'] = Crypt::encryptString($data['smtp_password']);
        }
        if (isset($data['imap_password'])) {
            $data['imap_password'] = Crypt::encryptString($data['imap_password']);
        }

        return SeedMailbox::create($data);
    }

    public function update(SeedMailbox $seed, array $data): SeedMailbox
    {
        if (isset($data['daily_interaction_cap'])) {
            $data['daily_total_interaction_cap'] = $data['daily_interaction_cap'];
            unset($data['daily_interaction_cap']);
        }
        if (isset($data['provider'])) {
            $data['provider_type'] = $this->mapProviderType($data['provider']);
            unset($data['provider']);
        }

        if (isset($data['smtp_password'])) {
            $data['smtp_password'] = Crypt::encryptString($data['smtp_password']);
        }
        if (isset($data['imap_password'])) {
            $data['imap_password'] = Crypt::encryptString($data['imap_password']);
        }

        $seed->update($data);
        return $seed->refresh();
    }

    public function activate(SeedMailbox $seed): void
    {
        $seed->update(['status' => 'active']);
    }

    public function deactivate(SeedMailbox $seed): void
    {
        $seed->update(['status' => 'disabled']);
    }

    public function pause(SeedMailbox $seed, string $reason = 'manual', ?string $details = null): void
    {
        $seed->update(['is_paused' => true]);
        $seed->pauseRules()->create([
            'reason' => $reason,
            'details' => $details,
            'paused_at' => now(),
            'status' => 'active',
        ]);
    }

    public function resume(SeedMailbox $seed): void
    {
        $seed->update(['is_paused' => false]);
        $seed->pauseRules()->where('status', 'active')->update([
            'resumed_at' => now(),
            'status' => 'resumed',
        ]);
    }

    public function getAvailableSeeds(): \Illuminate\Database\Eloquent\Collection
    {
        return SeedMailbox::where('status', 'active')
            ->where('is_paused', false)
            ->get();
    }

    public function getDecryptedSmtpPassword(SeedMailbox $seed): string
    {
        return Crypt::decryptString($seed->smtp_password);
    }

    public function getDecryptedImapPassword(SeedMailbox $seed): string
    {
        return Crypt::decryptString($seed->imap_password);
    }

    private function mapProviderType(string $provider): string
    {
        return match ($provider) {
            'google' => 'gmail',
            'microsoft' => 'outlook',
            'yahoo' => 'yahoo',
            default => 'custom_smtp',
        };
    }
}
