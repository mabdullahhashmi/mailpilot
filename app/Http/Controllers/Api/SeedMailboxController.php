<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeedMailboxController extends Controller
{
    public function __construct(private SeedService $service) {}

    private const PROVIDER_PRESETS = [
        'google' => [
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
        ],
        'microsoft' => [
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_host' => 'outlook.office365.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
        ],
        'zoho' => [
            'smtp_host' => 'smtp.zoho.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_host' => 'imap.zoho.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
        ],
        'yahoo' => [
            'smtp_host' => 'smtp.mail.yahoo.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'imap_host' => 'imap.mail.yahoo.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
        ],
        'hostinger' => [
            'smtp_host' => 'smtp.hostinger.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'imap_host' => 'imap.hostinger.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
        ],
        'titan' => [
            'smtp_host' => 'smtp.titan.email',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'imap_host' => 'imap.titan.email',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
        ],
    ];

    public function index(): JsonResponse
    {
        $seeds = \App\Models\SeedMailbox::orderBy('created_at', 'desc')->get();
        return response()->json($seeds);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_address' => 'required|email|unique:seed_mailboxes,email_address',
            'smtp_host' => 'required|string',
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
            'daily_interaction_cap' => 'nullable|integer|min:1',
        ]);

        $seed = $this->service->create($validated);
        return response()->json($seed, 201);
    }

    /**
     * Quick bulk add using simple lines: email,app_password[,provider]
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bulk_text' => 'required|string',
            'provider' => 'nullable|in:google,microsoft,zoho,yahoo,hostinger,titan',
            'daily_interaction_cap' => 'nullable|integer|min:1|max:1000',
        ]);

        $defaultProvider = $validated['provider'] ?? 'google';
        $dailyCap = (int) ($validated['daily_interaction_cap'] ?? 20);

        $lines = preg_split('/\r\n|\r|\n/', (string) $validated['bulk_text']);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $i => $rawLine) {
            $lineNumber = $i + 1;
            $line = trim((string) $rawLine);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/[,;|\t]/', $line);
            $parts = array_map(fn ($v) => trim((string) $v), $parts ?: []);

            if (count($parts) < 2) {
                $errors[] = "Line {$lineNumber}: expected email,app_password[,provider]";
                $skipped++;
                continue;
            }

            $email = $parts[0] ?? '';
            $password = preg_replace('/\s+/', '', (string) ($parts[1] ?? ''));
            $provider = strtolower($parts[2] ?? $defaultProvider);
            $provider = match ($provider) {
                'hostinger-email', 'hostinger_business', 'hostingerbusiness' => 'hostinger',
                'titanmail', 'titan-mail' => 'titan',
                default => $provider,
            };

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Line {$lineNumber}: invalid email '{$email}'";
                $skipped++;
                continue;
            }

            if ($password === '') {
                $errors[] = "Line {$lineNumber}: missing app password for '{$email}'";
                $skipped++;
                continue;
            }

            if (!isset(self::PROVIDER_PRESETS[$provider])) {
                $errors[] = "Line {$lineNumber}: unsupported provider '{$provider}' for '{$email}'";
                $skipped++;
                continue;
            }

            if (\App\Models\SeedMailbox::where('email_address', $email)->exists()) {
                $errors[] = "Line {$lineNumber}: '{$email}' already exists";
                $skipped++;
                continue;
            }

            $preset = self::PROVIDER_PRESETS[$provider];

            try {
                $this->service->create([
                    'email_address' => $email,
                    'provider' => $provider,
                    'smtp_host' => $preset['smtp_host'],
                    'smtp_port' => $preset['smtp_port'],
                    'smtp_username' => $email,
                    'smtp_password' => $password,
                    'smtp_encryption' => $preset['smtp_encryption'],
                    'imap_host' => $preset['imap_host'],
                    'imap_port' => $preset['imap_port'],
                    'imap_username' => $email,
                    'imap_password' => $password,
                    'imap_encryption' => $preset['imap_encryption'],
                    'daily_interaction_cap' => $dailyCap,
                    'status' => 'active',
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Line {$lineNumber}: " . $e->getMessage();
                $skipped++;
            }
        }

        return response()->json([
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 50),
            'format' => 'email,app_password[,provider] (provider optional: google|microsoft|zoho|yahoo|hostinger|titan)',
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);
        return response()->json($seed);
    }

    /**
     * Detailed seed history grouped by sender mailbox.
     */
    public function history(int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);

        $threads = \App\Models\Thread::where('seed_mailbox_id', $seed->id)
            ->with([
                'senderMailbox:id,email_address,provider_type,status',
                'warmupCampaign:id,campaign_name,current_day_number,current_stage,status',
                'messages' => fn ($q) => $q->orderByDesc('sent_at')->orderByDesc('id'),
            ])
            ->orderByDesc('updated_at')
            ->limit(600)
            ->get();

        $groupedBySender = $threads
            ->groupBy('sender_mailbox_id')
            ->map(function ($senderThreads) {
                $sender = $senderThreads->first()?->senderMailbox;

                $messages = $senderThreads
                    ->flatMap(function ($thread) {
                        return $thread->messages->map(function ($m) use ($thread) {
                            return [
                                'thread_id' => $thread->id,
                                'campaign_id' => $thread->warmup_campaign_id,
                                'campaign_name' => $thread->warmupCampaign?->campaign_name,
                                'direction' => $m->direction,
                                'actor_type' => $m->actor_type,
                                'subject' => $m->subject,
                                'sent_at' => $m->sent_at?->toIso8601String(),
                            ];
                        });
                    })
                    ->sortByDesc('sent_at')
                    ->values();

                $threadRows = $senderThreads->map(function ($thread) {
                    return [
                        'thread_id' => $thread->id,
                        'campaign_id' => $thread->warmup_campaign_id,
                        'campaign_name' => $thread->warmupCampaign?->campaign_name,
                        'thread_status' => $thread->thread_status,
                        'planned_message_count' => (int) ($thread->planned_message_count ?? 0),
                        'actual_message_count' => (int) ($thread->actual_message_count ?? 0),
                        'subject_line' => $thread->subject_line,
                        'created_at' => $thread->created_at?->toIso8601String(),
                        'updated_at' => $thread->updated_at?->toIso8601String(),
                    ];
                })->values();

                $campaigns = $senderThreads
                    ->map(fn ($thread) => [
                        'campaign_id' => $thread->warmup_campaign_id,
                        'campaign_name' => $thread->warmupCampaign?->campaign_name,
                        'campaign_status' => $thread->warmupCampaign?->status,
                        'current_day_number' => $thread->warmupCampaign?->current_day_number,
                        'current_stage' => $thread->warmupCampaign?->current_stage,
                    ])
                    ->unique('campaign_id')
                    ->values();

                return [
                    'sender_mailbox_id' => $sender?->id,
                    'sender_email' => $sender?->email_address,
                    'sender_provider' => $sender?->provider_type,
                    'sender_status' => $sender?->status,
                    'threads_count' => $senderThreads->count(),
                    'active_threads' => $senderThreads->whereIn('thread_status', ['planned', 'active', 'closing'])->count(),
                    'messages_count' => $messages->count(),
                    'last_interaction_at' => $messages->first()['sent_at'] ?? null,
                    'campaigns' => $campaigns,
                    'threads' => $threadRows,
                    'messages' => $messages->take(200)->values(),
                ];
            })
            ->values();

        $usage30d = \App\Models\SeedUsageLog::where('seed_mailbox_id', $seed->id)
            ->where('log_date', '>=', today()->subDays(30))
            ->orderByDesc('log_date')
            ->get()
            ->map(function ($log) {
                return [
                    'log_date' => $log->log_date?->format('Y-m-d'),
                    'interactions_today' => (int) ($log->interactions_today ?? 0),
                    'per_sender_usage' => $log->per_sender_usage ?? [],
                    'per_domain_usage' => $log->per_domain_usage ?? [],
                    'health_score' => (int) ($log->health_score ?? 0),
                    'overload_flag' => (bool) ($log->overload_flag ?? false),
                    'is_paused' => (bool) ($log->is_paused ?? false),
                ];
            })
            ->values();

        $summary = [
            'total_threads' => $threads->count(),
            'active_threads' => $threads->whereIn('thread_status', ['planned', 'active', 'closing'])->count(),
            'closed_threads' => $threads->where('thread_status', 'closed')->count(),
            'unique_senders' => $threads->pluck('sender_mailbox_id')->unique()->count(),
            'messages_total' => $threads->sum(fn ($thread) => $thread->messages->count()),
            'interactions_30d' => (int) $usage30d->sum('interactions_today'),
        ];

        return response()->json([
            'seed' => $seed,
            'summary' => $summary,
            'by_sender' => $groupedBySender,
            'usage_30d' => $usage30d,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'smtp_host' => 'sometimes|string',
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
            'daily_interaction_cap' => 'nullable|integer|min:1',
        ]);

        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $updated = $this->service->update($seed, $validated);
        return response()->json($updated);
    }

    public function testSmtp(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'test_email' => 'nullable|email',
        ]);

        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $result = $this->service->testSmtp($seed, $validated['test_email'] ?? null);

        return response()->json($result);
    }

    public function testImap(int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $result = $this->service->testImap($seed);

        return response()->json($result);
    }

    public function inbox(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'folder' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $result = $this->service->fetchInbox(
            $seed,
            (int) ($validated['limit'] ?? 30),
            (string) ($validated['folder'] ?? 'INBOX')
        );

        return response()->json($result);
    }

    public function testAllConnections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:all,active,paused,disabled,failed',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        @set_time_limit(0);

        $summary = $this->service->testAllConnections(
            (string) ($validated['status'] ?? 'all'),
            (int) ($validated['limit'] ?? 500)
        );

        return response()->json([
            'success' => true,
            ...$summary,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);

        DB::transaction(function () use ($seed) {
            $seed->threads()->delete();
            $seed->delete();
        });

        return response()->json(['message' => 'Deleted']);
    }

    public function pause(Request $request, int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $this->service->pause($seed, $request->input('reason', 'Manual pause'));
        return response()->json(['message' => 'Paused']);
    }

    public function resume(int $id): JsonResponse
    {
        $seed = \App\Models\SeedMailbox::findOrFail($id);
        $this->service->resume($seed);
        return response()->json(['message' => 'Resumed']);
    }
}
