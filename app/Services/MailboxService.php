<?php

namespace App\Services;

use App\Models\SenderMailbox;
use App\Models\Domain;
use Illuminate\Support\Facades\Crypt;

class MailboxService
{
    private const SMTP_TEST_TIMEOUT_SECONDS = 12.0;

    public function __construct(private InboxFetchService $inboxFetchService) {}

    public function create(array $data): SenderMailbox
    {
        $domain = $this->resolveOrCreateDomain($data['email_address']);
        $data['domain_id'] = $domain->id;

        $data = $this->applyDefaults($data);

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
        $data = $this->applyDefaults($data);

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
            $transport->getStream()->setTimeout(self::SMTP_TEST_TIMEOUT_SECONDS);
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

    public function testSmtpCredentials(array $credentials, ?string $testEmail = null): array
    {
        try {
            $smtpHost = trim((string) ($credentials['smtp_host'] ?? ''));
            $smtpPort = (int) ($credentials['smtp_port'] ?? 0);
            $smtpUsername = trim((string) ($credentials['smtp_username'] ?? ''));
            $smtpPassword = (string) ($credentials['smtp_password'] ?? '');
            $smtpEncryption = strtolower(trim((string) ($credentials['smtp_encryption'] ?? 'tls')));
            $fromEmail = trim((string) ($credentials['email_address'] ?? $smtpUsername));

            if ($smtpHost === '' || str_contains($smtpHost, '@')) {
                return [
                    'success' => false,
                    'message' => 'Invalid SMTP host. Use server host like smtp.gmail.com or smtp.office365.com (not an email address).',
                ];
            }

            if ($smtpPort <= 0 || $smtpUsername === '' || $smtpPassword === '') {
                return [
                    'success' => false,
                    'message' => 'SMTP port, username, and password are required to verify SMTP.',
                ];
            }

            if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
                return [
                    'success' => false,
                    'message' => 'SMTP encryption must be one of: tls, ssl, none.',
                ];
            }

            $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
                $smtpHost,
                $smtpPort,
                $smtpEncryption === 'ssl'
            );
            $transport->getStream()->setTimeout(self::SMTP_TEST_TIMEOUT_SECONDS);
            $transport->setUsername($smtpUsername);
            $transport->setPassword($smtpPassword);
            $transport->start();

            $testEmail = trim((string) ($testEmail ?? ''));
            if ($testEmail !== '') {
                $mailer = new \Symfony\Component\Mailer\Mailer($transport);
                $email = (new \Symfony\Component\Mime\Email())
                    ->from($fromEmail)
                    ->to($testEmail)
                    ->subject('MailPilot SMTP Credential Test - ' . now()->format('Y-m-d H:i:s'))
                    ->text("This is a MailPilot SMTP credential test message.\n\nSender: {$fromEmail}\nSMTP Host: {$smtpHost}\nTime: " . now()->toDateTimeString())
                    ->html('<p>This is a <strong>MailPilot SMTP credential test message</strong>.</p><p>Sender: ' . e($fromEmail) . '<br>SMTP Host: ' . e($smtpHost) . '<br>Time: ' . e(now()->toDateTimeString()) . '</p>');

                $mailer->send($email);
            }

            $transport->stop();

            return [
                'success' => true,
                'test_email_sent' => $testEmail !== '',
                'test_email' => $testEmail !== '' ? $testEmail : null,
                'message' => $testEmail !== ''
                    ? "SMTP connected and test email sent to {$testEmail}"
                    : 'SMTP connection successful',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function testImap(SenderMailbox $mailbox): array
    {
        try {
            if (!function_exists('imap_open')) {
                $mailbox->update([
                    'last_imap_test_at' => now(),
                    'last_imap_test_result' => 'fail',
                ]);

                return [
                    'success' => false,
                    'message' => 'PHP IMAP extension is not enabled on this server.',
                ];
            }

            $imapHost = trim((string) ($mailbox->imap_host ?? ''));
            $imapPort = (int) ($mailbox->imap_port ?? 0);
            $imapUsername = trim((string) ($mailbox->imap_username ?? ''));
            $imapPasswordEncrypted = (string) ($mailbox->imap_password ?? '');
            $imapEncryption = strtolower(trim((string) ($mailbox->imap_encryption ?? 'ssl')));

            if ($imapHost === '' || str_contains($imapHost, '@')) {
                $mailbox->update([
                    'last_imap_test_at' => now(),
                    'last_imap_test_result' => 'fail',
                ]);

                return [
                    'success' => false,
                    'message' => 'Invalid IMAP host. Use server host like imap.gmail.com (not an email address).',
                ];
            }

            if ($imapPort <= 0 || $imapUsername === '' || $imapPasswordEncrypted === '') {
                $mailbox->update([
                    'last_imap_test_at' => now(),
                    'last_imap_test_result' => 'fail',
                ]);

                return [
                    'success' => false,
                    'message' => 'IMAP host, port, username, and password are required to verify IMAP.',
                ];
            }

            if (!in_array($imapEncryption, ['tls', 'ssl', 'none'], true)) {
                $mailbox->update([
                    'last_imap_test_at' => now(),
                    'last_imap_test_result' => 'fail',
                ]);

                return [
                    'success' => false,
                    'message' => 'IMAP encryption must be one of: tls, ssl, none.',
                ];
            }

            $password = Crypt::decryptString($mailbox->imap_password);

            if (defined('IMAP_OPENTIMEOUT')) {
                @imap_timeout(IMAP_OPENTIMEOUT, 8);
            }

            $connStrings = $this->buildImapConnectionStrings($imapHost, $imapPort, $imapEncryption);
            $connection = false;

            foreach ($connStrings as $connString) {
                $connection = @imap_open($connString, $imapUsername, $password, 0, 1);
                if ($connection) {
                    break;
                }
            }

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

    private function buildImapConnectionStrings(string $host, int $port, string $encryption): array
    {
        $base = '{' . $host . ':' . $port;

        return match ($encryption) {
            'ssl' => [
                $base . '/imap/ssl/novalidate-cert}INBOX',
            ],
            'tls' => [
                $base . '/imap/tls/novalidate-cert}INBOX',
            ],
            default => [
                $base . '/imap/notls}INBOX',
            ],
        };
    }

    public function fetchInbox(SenderMailbox $mailbox, int $limit = 30, string $folder = 'INBOX'): array
    {
        return $this->inboxFetchService->fetchInbox($mailbox, $limit, $folder);
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

    private function applyDefaults(array $data): array
    {
        if (array_key_exists('timezone', $data) && ($data['timezone'] === null || trim((string) $data['timezone']) === '')) {
            $data['timezone'] = 'UTC';
        }

        if (array_key_exists('working_hours_start', $data) && ($data['working_hours_start'] === null || trim((string) $data['working_hours_start']) === '')) {
            $data['working_hours_start'] = '08:00:00';
        }

        if (array_key_exists('working_hours_end', $data) && ($data['working_hours_end'] === null || trim((string) $data['working_hours_end']) === '')) {
            $data['working_hours_end'] = '18:00:00';
        }

        if (array_key_exists('smtp_encryption', $data) && ($data['smtp_encryption'] === null || trim((string) $data['smtp_encryption']) === '')) {
            $data['smtp_encryption'] = 'tls';
        }

        if (array_key_exists('imap_encryption', $data) && ($data['imap_encryption'] === null || trim((string) $data['imap_encryption']) === '')) {
            $data['imap_encryption'] = 'ssl';
        }

        return $data;
    }
}
