<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

class InboxFetchService
{
    public function fetchInbox(object $mailbox, int $limit = 30, string $folder = 'INBOX'): array
    {
        if (!function_exists('imap_open')) {
            return [
                'success' => false,
                'message' => 'PHP IMAP extension is not enabled on this server.',
            ];
        }

        $limit = max(1, min(100, $limit));
        $folder = trim($folder) !== '' ? trim($folder) : 'INBOX';

        if (str_contains($folder, '{') || str_contains($folder, '}')) {
            return [
                'success' => false,
                'message' => 'Invalid mailbox folder name.',
            ];
        }

        [$ok, $configOrError] = $this->resolveConfig($mailbox);
        if (!$ok) {
            return [
                'success' => false,
                'message' => $configOrError,
            ];
        }

        $config = $configOrError;
        $basePath = '{' . $config['host'] . ':' . $config['port'] . $this->imapFlags($config['encryption']) . '}';
        $inboxPath = $basePath . 'INBOX';

        $imap = @imap_open($inboxPath, $config['username'], $config['password'], 0, 1);
        if (!$imap) {
            return [
                'success' => false,
                'message' => imap_last_error() ?: 'IMAP connection failed',
            ];
        }

        try {
            if (strtoupper($folder) !== 'INBOX') {
                if (!@imap_reopen($imap, $basePath . $folder)) {
                    return [
                        'success' => false,
                        'message' => "Failed to open folder '{$folder}': " . (imap_last_error() ?: 'Unknown IMAP folder error'),
                    ];
                }
            }

            $totalMessages = (int) (@imap_num_msg($imap) ?: 0);

            $uids = @imap_sort($imap, SORTDATE, 1, SE_UID);
            if (!is_array($uids) || empty($uids)) {
                $uids = @imap_search($imap, 'ALL', SE_UID) ?: [];
                if (is_array($uids) && !empty($uids)) {
                    rsort($uids, SORT_NUMERIC);
                }
            }

            $messages = [];
            foreach (array_slice((array) $uids, 0, $limit) as $uid) {
                $overview = @imap_fetch_overview($imap, (string) $uid, FT_UID);
                $entry = $overview[0] ?? null;
                if (!$entry) {
                    continue;
                }

                $rawDate = (string) ($entry->date ?? '');
                $dateIso = null;
                if ($rawDate !== '') {
                    try {
                        $dateIso = Carbon::parse($rawDate)->toIso8601String();
                    } catch (\Throwable $e) {
                        $dateIso = null;
                    }
                }

                $messages[] = [
                    'uid' => (int) $uid,
                    'message_no' => (int) ($entry->msgno ?? 0),
                    'subject' => $this->decodeMimeHeader((string) ($entry->subject ?? '')),
                    'from' => $this->decodeMimeHeader((string) ($entry->from ?? '')),
                    'to' => $this->decodeMimeHeader((string) ($entry->to ?? '')),
                    'date' => $rawDate,
                    'date_iso' => $dateIso,
                    'size' => (int) ($entry->size ?? 0),
                    'seen' => $this->overviewFlag($entry, 'seen'),
                    'answered' => $this->overviewFlag($entry, 'answered'),
                    'flagged' => $this->overviewFlag($entry, 'flagged'),
                    'deleted' => $this->overviewFlag($entry, 'deleted'),
                    'draft' => $this->overviewFlag($entry, 'draft'),
                    'recent' => $this->overviewFlag($entry, 'recent'),
                ];
            }

            return [
                'success' => true,
                'mailbox_email' => (string) ($mailbox->email_address ?? ''),
                'folder' => $folder,
                'total_messages' => $totalMessages,
                'returned_messages' => count($messages),
                'messages' => $messages,
            ];
        } finally {
            @imap_close($imap);
        }
    }

    private function resolveConfig(object $mailbox): array
    {
        $host = trim((string) ($mailbox->imap_host ?? $mailbox->smtp_host ?? ''));
        $port = (int) ($mailbox->imap_port ?: 993);
        $encryption = strtolower(trim((string) ($mailbox->imap_encryption ?? 'ssl')));
        $username = trim((string) ($mailbox->imap_username ?? $mailbox->smtp_username ?? ''));
        $passwordCipher = (string) ($mailbox->imap_password ?? $mailbox->smtp_password ?? '');

        if ($host === '' || str_contains($host, '@')) {
            return [false, 'Invalid IMAP host. Use server host like imap.gmail.com.'];
        }

        if ($port <= 0 || $username === '' || $passwordCipher === '') {
            return [false, 'IMAP host, port, username, and password are required.'];
        }

        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            return [false, 'IMAP encryption must be one of: ssl, tls, none.'];
        }

        try {
            $password = Crypt::decryptString($passwordCipher);
        } catch (\Throwable $e) {
            // Fallback for environments where old data may not be encrypted.
            $password = $passwordCipher;
        }

        return [true, [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
        ]];
    }

    private function imapFlags(string $encryption): string
    {
        return match ($encryption) {
            'ssl' => '/imap/ssl/novalidate-cert',
            'tls' => '/imap/tls/novalidate-cert',
            default => '/imap/notls',
        };
    }

    private function overviewFlag(object $entry, string $property): bool
    {
        return (int) ($entry->{$property} ?? 0) === 1;
    }

    private function decodeMimeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (!function_exists('imap_mime_header_decode')) {
            return $value;
        }

        $decoded = @imap_mime_header_decode($value);
        if (!is_array($decoded) || empty($decoded)) {
            return $value;
        }

        $text = '';
        foreach ($decoded as $part) {
            $chunk = (string) ($part->text ?? '');
            $charset = strtolower((string) ($part->charset ?? 'default'));

            if ($chunk !== '' && $charset !== 'default' && $charset !== 'utf-8' && function_exists('iconv')) {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $chunk);
                if ($converted !== false) {
                    $chunk = $converted;
                }
            }

            $text .= $chunk;
        }

        return trim($text) !== '' ? $text : $value;
    }
}
