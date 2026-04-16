<?php

namespace App\Services;

use App\Models\SeedMailbox;
use Illuminate\Support\Facades\Crypt;

class SeedService
{
    private const SMTP_TEST_TIMEOUT_SECONDS = 12.0;

    public function __construct(private InboxFetchService $inboxFetchService) {}

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

    public function testSmtp(SeedMailbox $seed, ?string $testEmail = null): array
    {
        try {
            $smtpHost = trim((string) ($seed->smtp_host ?? ''));
            $smtpPort = (int) ($seed->smtp_port ?? 0);
            $smtpUsername = trim((string) ($seed->smtp_username ?? ''));
            $smtpPasswordEncrypted = (string) ($seed->smtp_password ?? '');
            $smtpEncryption = strtolower(trim((string) ($seed->smtp_encryption ?? 'tls')));

            if ($smtpHost === '' || str_contains($smtpHost, '@')) {
                $this->recordSmtpTestResult($seed, false);
                return [
                    'success' => false,
                    'message' => 'Invalid SMTP host. Use server host like smtp.gmail.com or smtp.office365.com (not an email address).',
                ];
            }

            if ($smtpPort <= 0 || $smtpUsername === '' || $smtpPasswordEncrypted === '') {
                $this->recordSmtpTestResult($seed, false);
                return [
                    'success' => false,
                    'message' => 'SMTP port, username, and password are required to verify SMTP.',
                ];
            }

            if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
                $this->recordSmtpTestResult($seed, false);
                return [
                    'success' => false,
                    'message' => 'SMTP encryption must be one of: tls, ssl, none.',
                ];
            }

            $password = Crypt::decryptString($smtpPasswordEncrypted);

            $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
                $smtpHost,
                $smtpPort,
                $smtpEncryption === 'ssl'
            );
            $transport->getStream()->setTimeout(self::SMTP_TEST_TIMEOUT_SECONDS);
            if ($smtpEncryption === 'none' && method_exists($transport, 'setAutoTls')) {
                $transport->setAutoTls(false);
            }
            $transport->setUsername($smtpUsername);
            $transport->setPassword($password);
            $transport->start();

            $testEmail = trim((string) ($testEmail ?? ''));
            if ($testEmail !== '') {
                $mailer = new \Symfony\Component\Mailer\Mailer($transport);
                $email = (new \Symfony\Component\Mime\Email())
                    ->from($seed->email_address)
                    ->to($testEmail)
                    ->subject('MailPilot Seed SMTP Test - ' . now()->format('Y-m-d H:i:s'))
                    ->text("This is a MailPilot seed SMTP test message.\n\nSeed: {$seed->email_address}\nSMTP Host: {$smtpHost}\nTime: " . now()->toDateTimeString())
                    ->html('<p>This is a <strong>MailPilot seed SMTP test message</strong>.</p><p>Seed: ' . e($seed->email_address) . '<br>SMTP Host: ' . e($smtpHost) . '<br>Time: ' . e(now()->toDateTimeString()) . '</p>');

                $mailer->send($email);
            }

            $transport->stop();
            $this->recordSmtpTestResult($seed, true);

            return [
                'success' => true,
                'test_email_sent' => $testEmail !== '',
                'test_email' => $testEmail !== '' ? $testEmail : null,
                'message' => $testEmail !== ''
                    ? "SMTP connected and test email sent to {$testEmail}"
                    : 'SMTP connection successful',
            ];
        } catch (\Throwable $e) {
            $this->recordSmtpTestResult($seed, false);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function testImap(SeedMailbox $seed): array
    {
        try {
            if (!function_exists('imap_open')) {
                $this->recordImapTestResult($seed, false);
                return [
                    'success' => false,
                    'message' => 'PHP IMAP extension is not enabled on this server.',
                ];
            }

            $imapHost = trim((string) ($seed->imap_host ?? ''));
            $imapPort = (int) ($seed->imap_port ?? 0);
            $imapUsername = trim((string) ($seed->imap_username ?? ''));
            $imapPasswordEncrypted = (string) ($seed->imap_password ?? '');
            $imapEncryption = strtolower(trim((string) ($seed->imap_encryption ?? 'ssl')));

            if ($imapHost === '' || str_contains($imapHost, '@')) {
                $this->recordImapTestResult($seed, false);
                return [
                    'success' => false,
                    'message' => 'Invalid IMAP host. Use server host like imap.gmail.com (not an email address).',
                ];
            }

            if ($imapPort <= 0 || $imapUsername === '' || $imapPasswordEncrypted === '') {
                $this->recordImapTestResult($seed, false);
                return [
                    'success' => false,
                    'message' => 'IMAP host, port, username, and password are required to verify IMAP.',
                ];
            }

            if (!in_array($imapEncryption, ['tls', 'ssl', 'none'], true)) {
                $this->recordImapTestResult($seed, false);
                return [
                    'success' => false,
                    'message' => 'IMAP encryption must be one of: tls, ssl, none.',
                ];
            }

            $password = Crypt::decryptString($imapPasswordEncrypted);

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
            $this->recordImapTestResult($seed, true);

            return ['success' => true, 'message' => 'IMAP connection successful'];
        } catch (\Throwable $e) {
            $this->recordImapTestResult($seed, false);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function fetchInbox(SeedMailbox $seed, int $limit = 30, string $folder = 'INBOX'): array
    {
        return $this->inboxFetchService->fetchInbox($seed, $limit, $folder);
    }

    public function testAllConnections(?string $status = null, int $limit = 500): array
    {
        $query = SeedMailbox::query()->orderBy('id');

        $normalizedStatus = strtolower(trim((string) ($status ?? 'all')));
        if ($normalizedStatus !== '' && $normalizedStatus !== 'all') {
            $query->where('status', $normalizedStatus);
        }

        $limit = max(1, min(500, $limit));
        $seeds = $query->limit($limit)->get();

        $summary = [
            'total' => $seeds->count(),
            'fully_healthy' => 0,
            'with_issues' => 0,
            'smtp_pass' => 0,
            'smtp_fail' => 0,
            'imap_pass' => 0,
            'imap_fail' => 0,
            'issues' => [],
        ];

        foreach ($seeds as $seed) {
            $smtp = $this->testSmtp($seed);
            $imap = $this->testImap($seed);

            $smtpOk = (bool) ($smtp['success'] ?? false);
            $imapOk = (bool) ($imap['success'] ?? false);

            if ($smtpOk) {
                $summary['smtp_pass']++;
            } else {
                $summary['smtp_fail']++;
            }

            if ($imapOk) {
                $summary['imap_pass']++;
            } else {
                $summary['imap_fail']++;
            }

            if ($smtpOk && $imapOk) {
                $summary['fully_healthy']++;
            } else {
                $summary['with_issues']++;
                $summary['issues'][] = [
                    'seed_id' => $seed->id,
                    'email_address' => $seed->email_address,
                    'smtp' => [
                        'success' => $smtpOk,
                        'message' => $smtp['message'] ?? null,
                    ],
                    'imap' => [
                        'success' => $imapOk,
                        'message' => $imap['message'] ?? null,
                    ],
                ];
            }
        }

        $summary['checked_at'] = now()->toIso8601String();

        return $summary;
    }

    private function buildImapConnectionStrings(string $host, int $port, string $encryption): array
    {
        $base = '{' . $host . ':' . $port;

        return match ($encryption) {
            'ssl' => [
                $base . '/imap/ssl}INBOX',
                $base . '/imap/ssl/novalidate-cert}INBOX',
            ],
            'tls' => [
                $base . '/imap/tls}INBOX',
                $base . '/imap/tls/novalidate-cert}INBOX',
            ],
            default => [
                $base . '/imap/notls}INBOX',
            ],
        };
    }

    private function recordSmtpTestResult(SeedMailbox $seed, bool $success): void
    {
        $seed->update([
            'last_smtp_test_at' => now(),
            'last_smtp_test_result' => $success ? 'pass' : 'fail',
        ]);
    }

    private function recordImapTestResult(SeedMailbox $seed, bool $success): void
    {
        $seed->update([
            'last_imap_test_at' => now(),
            'last_imap_test_result' => $success ? 'pass' : 'fail',
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
