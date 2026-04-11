<?php

namespace App\Services;

use App\Models\SenderMailbox;
use App\Models\Domain;
use Illuminate\Support\Facades\Crypt;

class MailboxService
{
    public function create(array $data): SenderMailbox
    {
        $domain = $this->resolveOrCreateDomain($data['email_address']);
        $data['domain_id'] = $domain->id;

        // Map frontend field names to model columns
        if (isset($data['warmup_target_daily'])) {
            $data['daily_send_cap'] = $data['warmup_target_daily'];
            unset($data['warmup_target_daily']);
        }
        if (isset($data['daily_sending_cap'])) {
            $data['daily_send_cap'] = $data['daily_sending_cap'];
            unset($data['daily_sending_cap']);
        }
        // Map provider to provider_type
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

        return SenderMailbox::create($data);
    }

    public function update(SenderMailbox $mailbox, array $data): SenderMailbox
    {
        // Map frontend field names to model columns
        if (isset($data['warmup_target_daily'])) {
            $data['daily_send_cap'] = $data['warmup_target_daily'];
            unset($data['warmup_target_daily']);
        }
        if (isset($data['daily_sending_cap'])) {
            $data['daily_send_cap'] = $data['daily_sending_cap'];
            unset($data['daily_sending_cap']);
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

        $mailbox->update($data);
        return $mailbox->refresh();
    }

    public function activate(SenderMailbox $mailbox): void
    {
        $mailbox->update(['status' => 'active']);
    }

    public function deactivate(SenderMailbox $mailbox): void
    {
        $mailbox->update(['status' => 'disabled']);
    }

    public function pause(SenderMailbox $mailbox, string $reason = 'manual', ?string $details = null): void
    {
        $mailbox->update(['is_paused' => true]);
        $mailbox->pauseRules()->create([
            'reason' => $reason,
            'details' => $details,
            'paused_at' => now(),
            'status' => 'active',
        ]);
    }

    public function resume(SenderMailbox $mailbox): void
    {
        $mailbox->update(['is_paused' => false]);
        $mailbox->pauseRules()->where('status', 'active')->update([
            'resumed_at' => now(),
            'status' => 'resumed',
        ]);
    }

    public function testSmtp(SenderMailbox $mailbox, ?string $testEmail = null): array
    {
        try {
            if (!$mailbox->smtp_host || str_contains($mailbox->smtp_host, '@')) {
                $mailbox->update([
                    'last_smtp_test_at' => now(),
                    'last_smtp_test_result' => 'fail',
                ]);

                return [
                    'success' => false,
                    'message' => 'Invalid SMTP host. Use server host like smtp.gmail.com or smtp.office365.com (not an email address).',
                ];
            }

            $password = Crypt::decryptString($mailbox->smtp_password);

            $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
                $mailbox->smtp_host,
                $mailbox->smtp_port ?: 587,
                $mailbox->smtp_encryption === 'ssl' // true=implicit TLS (port 465), false=STARTTLS (port 587)
            );
            $transport->setUsername($mailbox->smtp_username);
            $transport->setPassword($password);
            $transport->start();

            if ($testEmail) {
                $mailer = new \Symfony\Component\Mailer\Mailer($transport);
                $email = (new \Symfony\Component\Mime\Email())
                    ->from($mailbox->email_address)
                    ->to($testEmail)
                    ->subject('MailPilot SMTP Test - ' . now()->format('Y-m-d H:i:s'))
                    ->text("This is a MailPilot SMTP test message.\n\nSender: {$mailbox->email_address}\nSMTP Host: {$mailbox->smtp_host}\nTime: " . now()->toDateTimeString())
                    ->html('<p>This is a <strong>MailPilot SMTP test message</strong>.</p><p>Sender: ' . e($mailbox->email_address) . '<br>SMTP Host: ' . e($mailbox->smtp_host) . '<br>Time: ' . e(now()->toDateTimeString()) . '</p>');

                $mailer->send($email);
            }

            $transport->stop();

            $mailbox->update([
                'last_smtp_test_at' => now(),
                'last_smtp_test_result' => 'pass',
            ]);

            return [
                'success' => true,
                'test_email_sent' => (bool) $testEmail,
                'test_email' => $testEmail,
                'message' => $testEmail
                    ? "SMTP connected and test email sent to {$testEmail}"
                    : 'SMTP connection successful',
            ];
        } catch (\Throwable $e) {
            $mailbox->update([
                'last_smtp_test_at' => now(),
                'last_smtp_test_result' => 'fail',
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function testImap(SenderMailbox $mailbox): array
    {
        try {
            $password = Crypt::decryptString($mailbox->imap_password);
            $encryption = $mailbox->imap_encryption === 'ssl' ? '/ssl' : '';
            $connString = '{' . $mailbox->imap_host . ':' . $mailbox->imap_port . '/imap' . $encryption . '}INBOX';

            $connection = @imap_open($connString, $mailbox->imap_username, $password);

            if (!$connection) {
                throw new \RuntimeException(imap_last_error() ?: 'IMAP connection failed');
            }

            imap_close($connection);

            $mailbox->update([
                'last_imap_test_at' => now(),
                'last_imap_test_result' => 'pass',
            ]);

            return ['success' => true, 'message' => 'IMAP connection successful'];
        } catch (\Throwable $e) {
            $mailbox->update([
                'last_imap_test_at' => now(),
                'last_imap_test_result' => 'fail',
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getDecryptedSmtpPassword(SenderMailbox $mailbox): string
    {
        return Crypt::decryptString($mailbox->smtp_password);
    }

    public function getDecryptedImapPassword(SenderMailbox $mailbox): string
    {
        return Crypt::decryptString($mailbox->imap_password);
    }

    private function resolveOrCreateDomain(string $email): Domain
    {
        $domainName = substr($email, strpos($email, '@') + 1);

        return Domain::firstOrCreate(
            ['domain_name' => $domainName],
            [
                'status' => 'active',
                'daily_domain_cap' => 50,
                'daily_growth_cap' => 5,
            ]
        );
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
