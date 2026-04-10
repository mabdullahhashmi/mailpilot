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

    public function testSmtp(SenderMailbox $mailbox): array
    {
        try {
            $password = Crypt::decryptString($mailbox->smtp_password);

            $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
                $mailbox->smtp_host,
                $mailbox->smtp_port,
                $mailbox->smtp_encryption === 'tls'
            );
            $transport->setUsername($mailbox->smtp_username);
            $transport->setPassword($password);
            $transport->start();
            $transport->stop();

            $mailbox->update([
                'last_smtp_test_at' => now(),
                'last_smtp_test_result' => 'pass',
            ]);

            return ['success' => true, 'message' => 'SMTP connection successful'];
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
}
