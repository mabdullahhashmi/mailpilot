<?php

namespace App\Services;

use App\Models\WarmupEvent;
use App\Models\WarmupEventLog;
use App\Models\Thread;
use App\Models\ThreadMessage;
use App\Models\SendSlot;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class EventExecutionService
{
    public function __construct(
        private ThreadService $threadService,
        private ContentService $content,
        private SafetyService $safety,
        private HealthService $health,
        private RandomizationService $randomizer,
        private ContentGuardService $contentGuard,
        private SeedHealthService $seedHealth,
        private SlotSchedulerService $slotScheduler,
        private BounceIntelligenceService $bounceIntelligence,
    ) {}

    /**
     * Execute a warmup event idempotently.
     */
    public function execute(WarmupEvent $event): void
    {
        $startTime = microtime(true);

        // Idempotency check
        if ($event->status === 'completed') {
            return;
        }

        $result = match ($event->event_type) {
            'sender_send_initial' => $this->executeSenderSendInitial($event),
            'seed_reply' => $this->executeSeedReply($event),
            'sender_reply' => $this->executeSenderReply($event),
            'seed_open_email' => $this->executeSeedOpen($event),
            'seed_mark_important' => $this->executeSeedMarkImportant($event),
            'seed_star_message' => $this->executeSeedStarMessage($event),
            'seed_remove_from_spam' => $this->executeSeedRemoveFromSpam($event),
            'thread_close' => $this->executeThreadClose($event),
            default => throw new \RuntimeException("Unknown event type: {$event->event_type}"),
        };

        $executionTime = (int)((microtime(true) - $startTime) * 1000);

        // If event was deferred (rescheduled), do NOT mark as completed
        $event->refresh();
        if ($event->status === 'pending') {
            // Event was rescheduled (e.g. seed_reply deferred due to pending open)
            Log::info("Event #{$event->id} was deferred/rescheduled, skipping completion.");
            return;
        }

        // Mark event completed
        $event->update([
            'status' => 'completed',
            'executed_at' => now(),
            'lock_token' => null,
            'lock_expires_at' => null,
        ]);

        // Log execution
        WarmupEventLog::create([
            'warmup_event_id' => $event->id,
            'thread_id' => $event->thread_id,
            'warmup_campaign_id' => $event->warmup_campaign_id,
            'event_type' => $event->event_type,
            'outcome' => 'success',
            'details' => $result['message'] ?? null,
            'execution_time_ms' => $executionTime,
        ]);

        // Update linked send slot
        $slot = SendSlot::where('warmup_event_id', $event->id)->first();
        if ($slot) {
            $this->slotScheduler->markSlotCompleted($slot);
        }

        // Schedule next event in thread if applicable
        if ($event->thread_id && isset($result['schedule_next']) && $result['schedule_next']) {
            $this->scheduleNextThreadEvent($event);
        }
    }

    private function executeSenderSendInitial(WarmupEvent $event): array
    {
        $thread = Thread::findOrFail($event->thread_id);
        $sender = $thread->senderMailbox;
        $seed = $thread->seedMailbox;

        // Safety check
        $this->safety->assertCanSend($sender, $thread->domain);

        // Generate content
        $campaign = $thread->warmupCampaign;
        $template = $this->content->selectInitialTemplate($campaign->current_stage);
        $body = $this->content->generateBody($template, $sender, $seed);
        $subject = $thread->subject_line ?: $this->content->generateSubject($template);

        // Send email via SMTP
        try {
            $messageId = $this->sendEmail($sender, $seed, $subject, $body);
        } catch (\Throwable $e) {
            $this->bounceIntelligence->recordBounce($sender, $seed->email_address, $e->getMessage(), $event->id, $thread->id);
            throw $e;
        }

        // Record content fingerprint for anti-pattern protection
        $this->contentGuard->recordUsage($sender, $seed->email_address, $body, $template);

        // Create thread message record
        $message = ThreadMessage::create([
            'thread_id' => $thread->id,
            'actor_type' => 'sender',
            'actor_mailbox_id' => $sender->id,
            'recipient_mailbox_id' => $seed->id,
            'direction' => 'sender_to_seed',
            'subject' => $subject,
            'body' => $body,
            'provider_message_id' => $messageId,
            'message_step_number' => 1,
            'sent_at' => now(),
            'delivery_state' => 'sent',
        ]);

        // Advance thread state
        $this->threadService->advanceThread($thread, $message);

        // Update health tracking (non-critical — never crash send)
        try {
            $this->health->recordSend($sender);
            $this->health->recordSeedInteraction($seed, $thread->domain);
        } catch (\Throwable $he) {
            Log::warning("Health tracking failed after send: {$he->getMessage()}");
        }

        return ['message' => "Sent initial email to {$seed->email_address}", 'schedule_next' => true];
    }

    private function executeSeedReply(WarmupEvent $event): array
    {
        $thread = Thread::findOrFail($event->thread_id);
        $seed = $thread->seedMailbox;
        $sender = $thread->senderMailbox;

        // Sequence enforcement: seed must have "opened" (seed_open_email) before replying
        $pendingOpen = WarmupEvent::where('thread_id', $thread->id)
            ->where('event_type', 'seed_open_email')
            ->where('status', 'pending')
            ->exists();

        if ($pendingOpen) {
            // Reschedule this reply to run after the open event — set status back to pending
            $event->update([
                'status' => 'pending',
                'scheduled_at' => now()->addMinutes(rand(5, 15)),
                'lock_token' => null,
                'lock_expires_at' => null,
            ]);
            return ['message' => "Reply deferred - open event pending for thread #{$thread->id}", 'schedule_next' => false];
        }

        // Safety check
        $this->safety->assertCanInteract($seed, $thread->domain);

        // Get last message to reply to
        $lastMessage = $thread->messages()->latest('message_step_number')->first();

        // Generate reply content (intent-aware)
        $campaign = $thread->warmupCampaign;
        $template = $this->content->selectReplyTemplate($campaign->current_stage, $thread, $lastMessage?->body);
        $body = $this->content->generateReplyBody($template, $seed, $sender, $lastMessage);

        // Send reply via seed SMTP
        try {
            $messageId = $this->sendEmail(
                $seed, $sender,
                'Re: ' . $thread->subject_line,
                $body,
                $lastMessage?->provider_message_id
            );
        } catch (\Throwable $e) {
            $this->bounceIntelligence->recordBounce($sender, $seed->email_address, $e->getMessage(), $event->id, $thread->id);
            throw $e;
        }

        $message = ThreadMessage::create([
            'thread_id' => $thread->id,
            'actor_type' => 'seed',
            'actor_mailbox_id' => $seed->id,
            'recipient_mailbox_id' => $sender->id,
            'direction' => 'seed_to_sender',
            'subject' => 'Re: ' . $thread->subject_line,
            'body' => $body,
            'provider_message_id' => $messageId,
            'in_reply_to_message_id' => $lastMessage?->provider_message_id,
            'message_step_number' => $thread->current_step_number + 1,
            'sent_at' => now(),
            'delivery_state' => 'sent',
        ]);

        $this->threadService->advanceThread($thread, $message);
        try {
            $this->health->recordReply($sender);
            $this->health->recordSeedInteraction($seed, $thread->domain);
            $this->seedHealth->recordSuccess($seed, 'reply');
        } catch (\Throwable $he) {
            Log::warning("Health tracking failed after reply: {$he->getMessage()}");
        }

        // Content fingerprints are sender-scoped; skip logging for seed-authored replies.

        return ['message' => "Seed replied in thread #{$thread->id}", 'schedule_next' => true];
    }

    private function executeSenderReply(WarmupEvent $event): array
    {
        $thread = Thread::findOrFail($event->thread_id);
        $sender = $thread->senderMailbox;
        $seed = $thread->seedMailbox;

        $this->safety->assertCanSend($sender, $thread->domain);

        $lastMessage = $thread->messages()->latest('message_step_number')->first();

        $campaign = $thread->warmupCampaign;
        $template = $this->content->selectReplyTemplate($campaign->current_stage, $thread, $lastMessage?->body);
        $body = $this->content->generateReplyBody($template, $sender, $seed, $lastMessage);

        try {
            $messageId = $this->sendEmail(
                $sender, $seed,
                'Re: ' . $thread->subject_line,
                $body,
                $lastMessage?->provider_message_id
            );
        } catch (\Throwable $e) {
            $this->bounceIntelligence->recordBounce($sender, $seed->email_address, $e->getMessage(), $event->id, $thread->id);
            throw $e;
        }

        $message = ThreadMessage::create([
            'thread_id' => $thread->id,
            'actor_type' => 'sender',
            'actor_mailbox_id' => $sender->id,
            'recipient_mailbox_id' => $seed->id,
            'direction' => 'sender_to_seed',
            'subject' => 'Re: ' . $thread->subject_line,
            'body' => $body,
            'provider_message_id' => $messageId,
            'in_reply_to_message_id' => $lastMessage?->provider_message_id,
            'message_step_number' => $thread->current_step_number + 1,
            'sent_at' => now(),
            'delivery_state' => 'sent',
        ]);

        $this->threadService->advanceThread($thread, $message);
        try { $this->health->recordSend($sender); } catch (\Throwable $he) { Log::warning("Health recordSend failed: {$he->getMessage()}"); }
        $this->contentGuard->recordUsage($sender, $seed->email_address, $body, $template);

        return ['message' => "Sender replied in thread #{$thread->id}", 'schedule_next' => true];
    }

    private function executeSeedOpen(WarmupEvent $event): array
    {
        $thread = Thread::findOrFail($event->thread_id);
        $seed = $thread->seedMailbox;

        $imap = $this->connectImap($seed);
        if (!$imap) {
            return ['message' => 'Seed open recorded (IMAP unavailable)', 'schedule_next' => false];
        }

        try {
            // Search for the message by subject in INBOX
            $messageUid = $this->findMessageInMailbox($imap, $thread->subject_line, $thread->senderMailbox->email_address);

            if ($messageUid) {
                // Mark message as SEEN (this is what "open" means at IMAP level)
                imap_setflag_full($imap, (string)$messageUid, '\\Seen', ST_UID);

                // Also fetch headers to trigger provider tracking
                imap_fetchheader($imap, $messageUid, FT_UID);
                imap_fetchbody($imap, $messageUid, '1', FT_UID);
            }

            // Track open for health + sender health
            try {
                $this->seedHealth->recordSuccess($seed, 'open');
                $this->health->recordOpen($thread->senderMailbox);
            } catch (\Throwable $he) {
                Log::warning("Health tracking failed after open: {$he->getMessage()}");
            }

            return ['message' => $messageUid
                ? "Seed opened message (UID: {$messageUid})"
                : 'Seed open recorded (message not found in inbox)', 'schedule_next' => false];
        } finally {
            imap_close($imap);
        }
    }

    private function executeSeedMarkImportant(WarmupEvent $event): array
    {
        $thread = Thread::findOrFail($event->thread_id);
        $seed = $thread->seedMailbox;

        $imap = $this->connectImap($seed);
        if (!$imap) {
            return ['message' => 'Mark important skipped (IMAP unavailable)', 'schedule_next' => false];
        }

        try {
            $messageUid = $this->findMessageInMailbox($imap, $thread->subject_line, $thread->senderMailbox->email_address);

            if ($messageUid) {
                // Set \\Flagged (important/starred in most providers)
                imap_setflag_full($imap, (string)$messageUid, '\\Flagged', ST_UID);

                // Gmail-specific: try $Important label
                $mailboxes = imap_list($imap, $this->imapPath($seed), '*');
                if ($mailboxes && in_array($this->imapPath($seed) . '[Gmail]/Important', $mailboxes)) {
                    imap_mail_copy($imap, (string)$messageUid, '[Gmail]/Important', CP_UID);
                }
            }

            return ['message' => $messageUid
                ? "Message marked important (UID: {$messageUid})"
                : 'Mark important skipped (message not found)', 'schedule_next' => false];
        } finally {
            imap_close($imap);
        }
    }

    private function executeSeedStarMessage(WarmupEvent $event): array
    {
        $thread = Thread::findOrFail($event->thread_id);
        $seed = $thread->seedMailbox;

        $imap = $this->connectImap($seed);
        if (!$imap) {
            return ['message' => 'Star message skipped (IMAP unavailable)', 'schedule_next' => false];
        }

        try {
            $messageUid = $this->findMessageInMailbox($imap, $thread->subject_line, $thread->senderMailbox->email_address);

            if ($messageUid) {
                imap_setflag_full($imap, (string)$messageUid, '\\Flagged', ST_UID);
            }

            return ['message' => $messageUid
                ? "Message starred (UID: {$messageUid})"
                : 'Star skipped (message not found)', 'schedule_next' => false];
        } finally {
            imap_close($imap);
        }
    }

    private function executeSeedRemoveFromSpam(WarmupEvent $event): array
    {
        $thread = Thread::findOrFail($event->thread_id);
        $seed = $thread->seedMailbox;

        $imap = $this->connectImap($seed);
        if (!$imap) {
            return ['message' => 'Spam rescue skipped (IMAP unavailable)', 'schedule_next' => false];
        }

        try {
            // Search in spam/junk folders
            $spamFolders = ['[Gmail]/Spam', 'Junk', 'INBOX.Junk', 'Spam', 'INBOX.Spam', 'Junk Email', 'Junk E-mail'];
            $messageUid = null;
            $spamFolder = null;

            foreach ($spamFolders as $folder) {
                $fullPath = $this->imapPath($seed) . $folder;
                if (@imap_reopen($imap, $fullPath)) {
                    $messageUid = $this->findMessageInMailbox($imap, $thread->subject_line, $thread->senderMailbox->email_address);
                    if ($messageUid) {
                        $spamFolder = $folder;
                        break;
                    }
                }
            }

            if ($messageUid && $spamFolder) {
                // Move from spam to INBOX
                imap_mail_move($imap, (string)$messageUid, 'INBOX', CP_UID);
                imap_expunge($imap);

                // Reopen INBOX and mark as not-spam (flag as seen + not-junk)
                imap_reopen($imap, $this->imapPath($seed) . 'INBOX');
                $movedUid = $this->findMessageInMailbox($imap, $thread->subject_line, $thread->senderMailbox->email_address);
                if ($movedUid) {
                    imap_setflag_full($imap, (string)$movedUid, '\\Seen', ST_UID);
                    imap_clearflag_full($imap, (string)$movedUid, '$Junk', ST_UID);
                }

                return ['message' => "Rescued from {$spamFolder} to INBOX (UID: {$messageUid})", 'schedule_next' => false];
            }

            return ['message' => 'Spam rescue skipped (message not found in spam folders)', 'schedule_next' => false];
        } finally {
            imap_close($imap);
        }
    }

    private function executeThreadClose(WarmupEvent $event): array
    {
        $thread = Thread::findOrFail($event->thread_id);
        $this->threadService->closeThread($thread);
        return ['message' => "Thread #{$thread->id} closed", 'schedule_next' => false];
    }

    private function scheduleNextThreadEvent(WarmupEvent $completedEvent): void
    {
        $thread = Thread::find($completedEvent->thread_id);
        if (!$thread || $thread->isComplete() || $thread->shouldClose()) {
            // Schedule close event
            if ($thread && $thread->shouldClose() && $thread->thread_status !== 'closed') {
                WarmupEvent::create([
                    'event_type' => 'thread_close',
                    'actor_type' => 'system',
                    'thread_id' => $thread->id,
                    'warmup_campaign_id' => $completedEvent->warmup_campaign_id,
                    'scheduled_at' => now()->addMinutes(rand(1, 5)),
                    'status' => 'pending',
                    'priority' => 6,
                ]);
            }
            return;
        }

        $profile = $thread->warmupCampaign->profile;
        $delayMinutes = $this->randomizer->replyDelay($profile);

        $nextType = $thread->next_actor_type === 'seed' ? 'seed_reply' : 'sender_reply';
        $actorId = $thread->next_actor_type === 'seed' ? $thread->seed_mailbox_id : $thread->sender_mailbox_id;
        $recipientId = $thread->next_actor_type === 'seed' ? $thread->sender_mailbox_id : $thread->seed_mailbox_id;

        // Maybe schedule auxiliary events (open, star, mark important)
        $this->maybeScheduleAuxiliaryEvents($thread, $completedEvent, $delayMinutes);

        WarmupEvent::create([
            'event_type' => $nextType,
            'actor_type' => $thread->next_actor_type,
            'actor_mailbox_id' => $actorId,
            'recipient_type' => $thread->next_actor_type === 'seed' ? 'sender' : 'seed',
            'recipient_mailbox_id' => $recipientId,
            'thread_id' => $thread->id,
            'warmup_campaign_id' => $completedEvent->warmup_campaign_id,
            'scheduled_at' => now()->addMinutes($delayMinutes),
            'status' => 'pending',
            'priority' => 4,
        ]);
    }

    private function maybeScheduleAuxiliaryEvents(Thread $thread, WarmupEvent $event, int $replyDelay): void
    {
        // Before seed replies, schedule an "open" event
        if ($thread->next_actor_type === 'seed') {
            $openDelay = max(1, intval($replyDelay * 0.3));

            WarmupEvent::create([
                'event_type' => 'seed_open_email',
                'actor_type' => 'seed',
                'actor_mailbox_id' => $thread->seed_mailbox_id,
                'thread_id' => $thread->id,
                'warmup_campaign_id' => $event->warmup_campaign_id,
                'scheduled_at' => now()->addMinutes($openDelay),
                'status' => 'pending',
                'priority' => 3,
            ]);

            // 20% chance to mark important
            if (rand(1, 100) <= 20) {
                WarmupEvent::create([
                    'event_type' => 'seed_mark_important',
                    'actor_type' => 'seed',
                    'actor_mailbox_id' => $thread->seed_mailbox_id,
                    'thread_id' => $thread->id,
                    'warmup_campaign_id' => $event->warmup_campaign_id,
                    'scheduled_at' => now()->addMinutes($openDelay + rand(1, 5)),
                    'status' => 'pending',
                    'priority' => 7,
                ]);
            }
        }
    }

    /**
     * Send an email via SMTP using the actor's credentials.
     */
    private function sendEmail(
        $fromMailbox,
        $toMailbox,
        string $subject,
        string $body,
        ?string $inReplyTo = null
    ): string {
        try {
            $password = Crypt::decryptString($fromMailbox->smtp_password);
        } catch (\Exception $e) {
            throw new \RuntimeException("SMTP password decryption failed for {$fromMailbox->email_address}: " . $e->getMessage());
        }

        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            $fromMailbox->smtp_host,
            $fromMailbox->smtp_port ?: 587,
            $fromMailbox->smtp_encryption === 'ssl' // true=implicit TLS (port 465), false=STARTTLS (port 587)
        );
        $transport->setUsername($fromMailbox->smtp_username);
        $transport->setPassword($password);

        $email = (new \Symfony\Component\Mime\Email())
            ->from($fromMailbox->email_address)
            ->to($toMailbox->email_address)
            ->subject($subject)
            ->html($body);

        $messageId = sprintf('%s@%s', bin2hex(random_bytes(16)), $fromMailbox->smtp_host ?: 'mailpilot.app');

        if ($inReplyTo) {
            $replyId = trim($inReplyTo, " <>\t\n\r\0\x0B");
            if ($replyId !== '') {
                $email->getHeaders()->addIdHeader('In-Reply-To', $replyId);
                $email->getHeaders()->addIdHeader('References', $replyId);
            }
        }

        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        $mailer = new \Symfony\Component\Mailer\Mailer($transport);
        $mailer->send($email);

        return $messageId;
    }

    /**
     * Connect to a mailbox's IMAP server.
     */
    private function connectImap($mailbox): mixed
    {
        $host = $mailbox->imap_host ?? $mailbox->smtp_host;
        $port = $mailbox->imap_port ?? 993;
        $encryption = $mailbox->imap_encryption ?? 'ssl';
        $username = $mailbox->imap_username ?? $mailbox->smtp_username;

        try {
            $password = Crypt::decryptString($mailbox->imap_password ?? $mailbox->smtp_password);
        } catch (\Exception $e) {
            Log::warning("[WarmupEngine] IMAP password decrypt failed for {$mailbox->email_address}");
            return false;
        }

        $flags = $encryption === 'ssl' ? '/imap/ssl/novalidate-cert' : '/imap/notls';
        $path = "{{$host}:{$port}{$flags}}INBOX";

        try {
            $imap = @imap_open($path, $username, $password, 0, 1);
            return $imap ?: false;
        } catch (\Exception $e) {
            Log::warning("[WarmupEngine] IMAP connection failed for {$mailbox->email_address}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Build IMAP server path for a mailbox.
     */
    private function imapPath($mailbox): string
    {
        $host = $mailbox->imap_host ?? $mailbox->smtp_host;
        $port = $mailbox->imap_port ?? 993;
        $encryption = $mailbox->imap_encryption ?? 'ssl';
        $flags = $encryption === 'ssl' ? '/imap/ssl/novalidate-cert' : '/imap/notls';
        return "{{$host}:{$port}{$flags}}";
    }

    /**
     * Find a message UID in the current IMAP mailbox by subject and sender.
     */
    private function findMessageInMailbox($imap, string $subject, string $fromEmail): ?int
    {
        // Clean subject for IMAP search
        $cleanSubject = str_replace(['Re: ', 'RE: ', 'Fwd: ', 'FWD: '], '', $subject);
        $cleanSubject = substr($cleanSubject, 0, 60); // IMAP search limit

        $results = @imap_search($imap, 'SUBJECT "' . addcslashes($cleanSubject, '"\\') . '" FROM "' . $fromEmail . '"', SE_UID);

        if (!$results || empty($results)) {
            // Fallback: search by subject only
            $results = @imap_search($imap, 'SUBJECT "' . addcslashes($cleanSubject, '"\\') . '"', SE_UID);
        }

        if ($results && !empty($results)) {
            // Return the most recent match
            return (int) end($results);
        }

        return null;
    }
}
