<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SenderMailbox;
use App\Services\MailboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SenderMailboxController extends Controller
{
    public function __construct(private MailboxService $service) {}

    public function index(): JsonResponse
    {
        $mailboxes = \App\Models\SenderMailbox::with(['domain', 'warmupCampaigns'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($mailboxes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_address' => 'required|email|unique:sender_mailboxes,email_address',
            'smtp_host' => ['required', 'string', 'max:255', 'not_regex:/@/'],
            'smtp_port' => 'required|integer',
            'smtp_username' => 'required|string',
            'smtp_password' => 'required|string',
            'smtp_encryption' => 'required|in:tls,ssl,none',
            'imap_host' => 'nullable|string',
            'imap_port' => 'nullable|integer',
            'imap_username' => 'nullable|string',
            'imap_password' => 'nullable|string',
            'imap_encryption' => 'nullable|in:tls,ssl,none',
            'provider' => 'nullable|string',
            'daily_sending_cap' => 'nullable|integer|min:1',
            'warmup_target_daily' => 'nullable|integer|min:1',
            'working_hours_start' => 'nullable|string',
            'working_hours_end' => 'nullable|string',
            'timezone' => 'nullable|string',
        ], [
            'smtp_host.not_regex' => 'SMTP host must be a server host (e.g. smtp.gmail.com), not an email address.',
        ]);

        $mailbox = $this->service->create($validated);
        return response()->json($mailbox, 201);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rows' => 'required|array|min:1|max:300',
        ]);

        $rowRules = [
            'email_address' => 'required|email',
            'smtp_host' => ['required', 'string', 'max:255', 'not_regex:/@/'],
            'smtp_port' => 'required|integer',
            'smtp_username' => 'required|string',
            'smtp_password' => 'required|string',
            'smtp_encryption' => 'required|in:tls,ssl,none',
            'imap_host' => ['nullable', 'string', 'max:255', 'not_regex:/@/'],
            'imap_port' => 'nullable|integer',
            'imap_username' => 'nullable|string',
            'imap_password' => 'nullable|string',
            'imap_encryption' => 'nullable|in:tls,ssl,none',
            'provider' => 'nullable|string',
            'daily_sending_cap' => 'nullable|integer|min:1',
            'warmup_target_daily' => 'nullable|integer|min:1',
            'working_hours_start' => 'nullable|string',
            'working_hours_end' => 'nullable|string',
            'timezone' => 'nullable|string',
        ];

        $rowMessages = [
            'smtp_host.not_regex' => 'SMTP host must be a server host (e.g. smtp.gmail.com), not an email address.',
            'imap_host.not_regex' => 'IMAP host must be a server host (e.g. imap.gmail.com), not an email address.',
        ];

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $created = [];
        $seenEmails = [];

        foreach ($validated['rows'] as $index => $row) {
            $lineNumber = $index + 1;
            $normalized = $this->normalizeBulkRow((array) $row);

            $isBlankRow = $normalized['email_address'] === ''
                && $normalized['smtp_host'] === ''
                && $normalized['smtp_username'] === ''
                && ($normalized['smtp_password'] ?? '') === ''
                && ($normalized['imap_host'] ?? '') === ''
                && ($normalized['imap_username'] ?? '') === '';

            if ($isBlankRow) {
                continue;
            }

            $validator = Validator::make($normalized, $rowRules, $rowMessages);
            if ($validator->fails()) {
                $errors[] = "Row {$lineNumber}: " . $validator->errors()->first();
                $skipped++;
                continue;
            }

            $emailKey = strtolower((string) $normalized['email_address']);
            if (isset($seenEmails[$emailKey])) {
                $errors[] = "Row {$lineNumber}: duplicate email '{$normalized['email_address']}' in bulk table";
                $skipped++;
                continue;
            }

            if (SenderMailbox::whereRaw('LOWER(email_address) = ?', [$emailKey])->exists()) {
                $errors[] = "Row {$lineNumber}: sender '{$normalized['email_address']}' already exists";
                $skipped++;
                continue;
            }

            try {
                $mailbox = $this->service->create($normalized);
                $seenEmails[$emailKey] = true;
                $created[] = [
                    'id' => $mailbox->id,
                    'email_address' => $mailbox->email_address,
                ];
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$lineNumber}: {$e->getMessage()}";
                $skipped++;
            }
        }

        return response()->json([
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 100),
            'created' => $created,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::with(['domain', 'warmupCampaigns', 'healthLogs' => fn($q) => $q->latest()->take(30)])
            ->findOrFail($id);

        return response()->json($mailbox);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'smtp_host' => ['sometimes', 'string', 'max:255', 'not_regex:/@/'],
            'smtp_port' => 'sometimes|integer',
            'smtp_username' => 'sometimes|string',
            'smtp_password' => 'sometimes|string',
            'smtp_encryption' => 'sometimes|in:tls,ssl,none',
            'imap_host' => 'nullable|string',
            'imap_port' => 'nullable|integer',
            'imap_username' => 'nullable|string',
            'imap_password' => 'nullable|string',
            'imap_encryption' => 'nullable|in:tls,ssl,none',
            'provider' => 'nullable|string',
            'daily_sending_cap' => 'nullable|integer|min:1',
            'warmup_target_daily' => 'nullable|integer|min:1',
            'working_hours_start' => 'nullable|string',
            'working_hours_end' => 'nullable|string',
            'timezone' => 'nullable|string',
        ], [
            'smtp_host.not_regex' => 'SMTP host must be a server host (e.g. smtp.gmail.com), not an email address.',
        ]);

        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $updated = $this->service->update($mailbox, $validated);
        return response()->json($updated);
    }

    public function destroy(int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $mailbox->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function testSmtp(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'test_email' => 'nullable|email',
        ]);

        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $result = $this->service->testSmtp($mailbox, $validated['test_email'] ?? null);
        return response()->json($result);
    }

    public function testImap(int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $result = $this->service->testImap($mailbox);
        return response()->json($result);
    }

    public function testSmtpCredentials(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_address' => 'required|email',
            'smtp_host' => ['required', 'string', 'max:255', 'not_regex:/@/'],
            'smtp_port' => 'required|integer|min:1',
            'smtp_username' => 'required|string',
            'smtp_password' => 'required|string',
            'smtp_encryption' => 'required|in:tls,ssl,none',
            'test_email' => 'nullable|email',
        ], [
            'smtp_host.not_regex' => 'SMTP host must be a server host (e.g. smtp.gmail.com), not an email address.',
        ]);

        $result = $this->service->testSmtpCredentials($validated, $validated['test_email'] ?? null);
        return response()->json($result);
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $this->service->pause($mailbox, $request->input('reason', 'Manual pause'));
        return response()->json(['message' => 'Paused']);
    }

    public function resume(int $id): JsonResponse
    {
        $mailbox = \App\Models\SenderMailbox::findOrFail($id);
        $this->service->resume($mailbox);
        return response()->json(['message' => 'Resumed']);
    }

    private function normalizeBulkRow(array $row): array
    {
        $nullIfEmpty = static function ($value) {
            if ($value === null) {
                return null;
            }

            $trimmed = trim((string) $value);
            return $trimmed === '' ? null : $trimmed;
        };

        $intOrNull = static function ($value) {
            if ($value === null || $value === '') {
                return null;
            }

            return (int) $value;
        };

        return [
            'email_address' => trim((string) ($row['email_address'] ?? '')),
            'smtp_host' => trim((string) ($row['smtp_host'] ?? '')),
            'smtp_port' => $intOrNull($row['smtp_port'] ?? null),
            'smtp_username' => trim((string) ($row['smtp_username'] ?? '')),
            'smtp_password' => (string) ($row['smtp_password'] ?? ''),
            'smtp_encryption' => strtolower(trim((string) ($row['smtp_encryption'] ?? ''))),
            'imap_host' => $nullIfEmpty($row['imap_host'] ?? null),
            'imap_port' => $intOrNull($row['imap_port'] ?? null),
            'imap_username' => $nullIfEmpty($row['imap_username'] ?? null),
            'imap_password' => $nullIfEmpty($row['imap_password'] ?? null),
            'imap_encryption' => ($row['imap_encryption'] ?? '') !== ''
                ? strtolower(trim((string) ($row['imap_encryption'] ?? '')))
                : null,
            'provider' => $nullIfEmpty($row['provider'] ?? null),
            'daily_sending_cap' => $intOrNull($row['daily_sending_cap'] ?? null),
            'warmup_target_daily' => $intOrNull($row['warmup_target_daily'] ?? null),
            'working_hours_start' => $nullIfEmpty($row['working_hours_start'] ?? null),
            'working_hours_end' => $nullIfEmpty($row['working_hours_end'] ?? null),
            'timezone' => $nullIfEmpty($row['timezone'] ?? null),
        ];
    }
}
