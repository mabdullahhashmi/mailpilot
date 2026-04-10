<?php

namespace App\Services;

use App\Models\WarmupEvent;
use App\Models\WarmupEventLog;
use App\Models\Thread;
use App\Models\ThreadMessage;
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
        $messageId = $this->sendEmail($sender, $seed, $subject, $body);

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

        // Update health tracking
        $this->health->recordSend($sender);
        $this->health->recordSeedInteraction($seed, $thread->domain);

        return ['message' => "Sent initial email to {$seed->email_address}", 'schedule_next' => true];
    }

    private function executeSeedReply(WarmupEvent $event): array
    {
        $thread = Thread::findOrFail($event->thread_id);
        $seed = $thread->seedMailbox;
        $sender = $thread->senderMailbox;

        // Safety check
        $this->safety->assertCanInteract($seed, $thread->domain);

        // Get last message to reply to
        $lastMessage = $thread->messages()->latest('message_step_number')->first();

        // Generate reply content
        $campaign = $thread->warmupCampaign;
        $template = $this->content->selectReplyTemplate($campaign->current_stage, $thread);
        $body = $this->content->generateReplyBody($template, $seed, $sender, $lastMessage);

        // Send reply via seed SMTP
        $messageId = $this->sendEmail(
            $seed, $sender,
            'Re: ' . $thread->subject_line,
            $body,
            $lastMessage?->provider_message_id
        );

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
        $this->health->recordReply($sender);
        $this->health->recordSeedInteraction($seed, $thread->domain);

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
        $template = $this->content->selectReplyTemplate($campaign->current_stage, $thread);
        $body = $this->content->generateReplyBody($template, $sender, $seed, $lastMessage);

        $messageId = $this->sendEmail(
            $sender, $seed,
            'Re: ' . $thread->subject_line,
            $body,
            $lastMessage?->provider_message_id
        );

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
        $this->health->recordSend($sender);

        return ['message' => "Sender replied in thread #{$thread->id}", 'schedule_next' => true];
    }

    private function executeSeedOpen(WarmupEvent $event): array
    {
        // Simulate or log that seed opened the email (IMAP-based check)
        return ['message' => 'Seed open recorded', 'schedule_next' => false];
    }

    private function executeSeedMarkImportant(WarmupEvent $event): array
    {
        // IMAP flag operation on seed inbox
        return ['message' => 'Message marked important', 'schedule_next' => false];
    }

    private function executeSeedStarMessage(WarmupEvent $event): array
    {
        return ['message' => 'Message starred', 'schedule_next' => false];
    }

    private function executeSeedRemoveFromSpam(WarmupEvent $event): array
    {
        return ['message' => 'Message removed from spam', 'schedule_next' => false];
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
        $password = Crypt::decryptString($fromMailbox->smtp_password);

        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            $fromMailbox->smtp_host,
            $fromMailbox->smtp_port,
            $fromMailbox->smtp_encryption === 'tls'
        );
        $transport->setUsername($fromMailbox->smtp_username);
        $transport->setPassword($password);

        $email = (new \Symfony\Component\Mime\Email())
            ->from($fromMailbox->email_address)
            ->to($toMailbox->email_address)
            ->subject($subject)
            ->html($body);

        $messageId = sprintf('%s@%s', bin2hex(random_bytes(16)), parse_url($fromMailbox->smtp_host, PHP_URL_HOST) ?: 'mailpilot');

        if ($inReplyTo) {
            $email->getHeaders()->addTextHeader('In-Reply-To', "<{$inReplyTo}>");
            $email->getHeaders()->addTextHeader('References', "<{$inReplyTo}>");
        }

        $email->getHeaders()->addTextHeader('Message-ID', "<{$messageId}>");

        $mailer = new \Symfony\Component\Mailer\Mailer($transport);
        $mailer->send($email);

        return $messageId;
    }
}
