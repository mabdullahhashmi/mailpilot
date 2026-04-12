<?php

namespace App\Services;

use App\Jobs\ExecuteFlowTestStep;
use App\Models\FlowTestRun;
use App\Models\FlowTestStep;
use App\Models\SeedMailbox;
use App\Models\SenderMailbox;
use Illuminate\Support\Facades\Crypt;

class FlowTestService
{
    public function createRun(
        SenderMailbox $sender,
        array $seedIds,
        int $phaseCount,
        int $openDelaySeconds,
        int $starDelaySeconds,
        int $replyDelaySeconds,
        ?int $createdBy = null
    ): FlowTestRun {
        $seeds = SeedMailbox::where('status', 'active')
            ->whereIn('id', $seedIds)
            ->orderBy('email_address')
            ->get();

        if ($seeds->isEmpty()) {
            throw new \RuntimeException('No active seeds found for this test run.');
        }

        if ($seeds->count() !== count(array_unique($seedIds))) {
            throw new \RuntimeException('One or more selected seeds are inactive or unavailable.');
        }

        $run = FlowTestRun::create([
            'sender_mailbox_id' => $sender->id,
            'phase_count' => $phaseCount,
            'open_delay_seconds' => $openDelaySeconds,
            'star_delay_seconds' => $starDelaySeconds,
            'reply_delay_seconds' => $replyDelaySeconds,
            'status' => 'running',
            'created_by' => $createdBy,
            'started_at' => now(),
        ]);

        $baseTime = now()->addSeconds(2);

        foreach ($seeds as $seed) {
            $steps = $this->buildSeedSteps(
                $run,
                $sender,
                $seed,
                $phaseCount,
                $openDelaySeconds,
                $starDelaySeconds,
                $replyDelaySeconds,
                $baseTime
            );

            foreach ($steps as $stepData) {
                FlowTestStep::create($stepData);
            }
        }

        $this->dispatchPendingSteps($run);

        return $run->fresh(['senderMailbox:id,email_address']);
    }

    public function dispatchPendingSteps(FlowTestRun $run): void
    {
        $steps = FlowTestStep::where('flow_test_run_id', $run->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->get();

        foreach ($steps as $step) {
            ExecuteFlowTestStep::dispatch($step->id)
                ->onQueue('warmup')
                ->delay($step->scheduled_at);
        }
    }

    public function executeStep(FlowTestStep $step): array
    {
        $step->loadMissing(['run.senderMailbox', 'seedMailbox']);

        $run = $step->run;
        if (!$run) {
            throw new \RuntimeException('Flow test run not found for this step.');
        }

        $sender = $run->senderMailbox;
        $seed = $step->seedMailbox;

        if (!$sender || !$seed) {
            throw new \RuntimeException('Sender or seed mailbox missing for this step.');
        }

        $subject = $step->subject ?: ($step->payload['subject'] ?? "Flow Test {$run->id}");

        return match ($step->action_type) {
            'sender_send_initial' => $this->executeSenderSendInitial($sender, $seed, $subject, $step),
            'seed_open_email' => $this->executeOpen($seed, $subject, $sender->email_address, 'Seed'),
            'sender_open_email' => $this->executeOpen($sender, $subject, $seed->email_address, 'Sender'),
            'seed_star_message' => $this->executeStar($seed, $subject, $sender->email_address),
            'seed_reply' => $this->executeSeedReply($step, $sender, $seed, $subject),
            'sender_reply' => $this->executeSenderReply($step, $sender, $seed, $subject),
            default => throw new \RuntimeException("Unknown flow test action: {$step->action_type}"),
        };
    }

    public function refreshRunStatus(int $runId): void
    {
        $run = FlowTestRun::find($runId);
        if (!$run) {
            return;
        }

        $total = FlowTestStep::where('flow_test_run_id', $runId)->count();
        $completed = FlowTestStep::where('flow_test_run_id', $runId)->where('status', 'completed')->count();
        $failed = FlowTestStep::where('flow_test_run_id', $runId)->where('status', 'failed')->count();
        $skipped = FlowTestStep::where('flow_test_run_id', $runId)->where('status', 'skipped')->count();
        $active = FlowTestStep::where('flow_test_run_id', $runId)->whereIn('status', ['pending', 'executing'])->count();

        if ($active > 0) {
            return;
        }

        $run->update([
            'status' => $failed > 0 ? 'failed' : 'completed',
            'finished_at' => now(),
            'summary' => [
                'total_steps' => $total,
                'completed_steps' => $completed,
                'failed_steps' => $failed,
                'skipped_steps' => $skipped,
            ],
        ]);
    }

    private function executeSenderSendInitial(SenderMailbox $sender, SeedMailbox $seed, string $subject, FlowTestStep $step): array
    {
        $body = $this->buildMessageBody('sender_initial', $sender->email_address, $seed->email_address, $step->step_index);
        $messageId = $this->sendEmail($sender, $seed, $subject, $body);

        return [
            'notes' => "Sender sent initial test email to {$seed->email_address}",
            'message_id' => $messageId,
        ];
    }

    private function executeSeedReply(FlowTestStep $step, SenderMailbox $sender, SeedMailbox $seed, string $subject): array
    {
        $inReplyTo = $this->latestMessageId($step, ['sender_send_initial', 'sender_reply']);
        if (!$inReplyTo) {
            throw new \RuntimeException('Seed reply skipped because no prior sender message-id was found.');
        }

        $body = $this->buildMessageBody('seed_reply', $seed->email_address, $sender->email_address, $step->step_index);
        $messageId = $this->sendEmail($seed, $sender, 'Re: ' . $subject, $body, $inReplyTo);

        return [
            'notes' => "Seed replied to {$sender->email_address}",
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
        ];
    }

    private function executeSenderReply(FlowTestStep $step, SenderMailbox $sender, SeedMailbox $seed, string $subject): array
    {
        $inReplyTo = $this->latestMessageId($step, ['seed_reply']);
        if (!$inReplyTo) {
            throw new \RuntimeException('Sender reply skipped because no prior seed message-id was found.');
        }

        $body = $this->buildMessageBody('sender_reply', $sender->email_address, $seed->email_address, $step->step_index);
        $messageId = $this->sendEmail($sender, $seed, 'Re: ' . $subject, $body, $inReplyTo);

        return [
            'notes' => "Sender replied to {$seed->email_address}",
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
        ];
    }

    private function executeOpen(SenderMailbox|SeedMailbox $mailbox, string $subject, string $fromEmail, string $label): array
    {
        $imap = $this->connectImap($mailbox);
        if (!$imap) {
            return [
                'notes' => "{$label} open recorded (IMAP unavailable)",
            ];
        }

        try {
            $uid = $this->findMessageInMailbox($imap, $subject, $fromEmail);

            if ($uid) {
                imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
                imap_fetchheader($imap, $uid, FT_UID);
                imap_fetchbody($imap, $uid, '1', FT_UID);
            }

            return [
                'notes' => $uid
                    ? "{$label} opened message (UID: {$uid})"
                    : "{$label} open recorded (message not found)",
            ];
        } finally {
            imap_close($imap);
        }
    }

    private function executeStar(SeedMailbox $seed, string $subject, string $fromEmail): array
    {
        $imap = $this->connectImap($seed);
        if (!$imap) {
            return [
                'notes' => 'Seed star recorded (IMAP unavailable)',
            ];
        }

        try {
            $uid = $this->findMessageInMailbox($imap, $subject, $fromEmail);
            if ($uid) {
                imap_setflag_full($imap, (string) $uid, '\\Flagged', ST_UID);
            }

            return [
                'notes' => $uid
                    ? "Seed starred message (UID: {$uid})"
                    : 'Seed star recorded (message not found)',
            ];
        } finally {
            imap_close($imap);
        }
    }

    private function latestMessageId(FlowTestStep $step, array $actionTypes): ?string
    {
        return FlowTestStep::where('flow_test_run_id', $step->flow_test_run_id)
            ->where('seed_mailbox_id', $step->seed_mailbox_id)
            ->whereIn('action_type', $actionTypes)
            ->where('status', 'completed')
            ->whereNotNull('message_id')
            ->orderByDesc('step_index')
            ->value('message_id');
    }

    /**
     * Send an email via SMTP using mailbox credentials.
     */
    private function sendEmail(
        SenderMailbox|SeedMailbox $fromMailbox,
        SenderMailbox|SeedMailbox $toMailbox,
        string $subject,
        string $body,
        ?string $inReplyTo = null
    ): string {
        $password = Crypt::decryptString($fromMailbox->smtp_password);

        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            $fromMailbox->smtp_host,
            $fromMailbox->smtp_port ?: 587,
            $fromMailbox->smtp_encryption === 'ssl'
        );

        $transport->setUsername($fromMailbox->smtp_username);
        $transport->setPassword($password);

        $email = (new \Symfony\Component\Mime\Email())
            ->from($fromMailbox->email_address)
            ->to($toMailbox->email_address)
            ->subject($subject)
            ->html($body);

        $messageId = sprintf('%s@%s', bin2hex(random_bytes(16)), $fromMailbox->smtp_host ?: 'mailpilot.test');

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

    private function connectImap(SenderMailbox|SeedMailbox $mailbox): mixed
    {
        $host = $mailbox->imap_host ?? $mailbox->smtp_host;
        $port = $mailbox->imap_port ?? 993;
        $encryption = $mailbox->imap_encryption ?? 'ssl';
        $username = $mailbox->imap_username ?? $mailbox->smtp_username;

        $password = Crypt::decryptString($mailbox->imap_password ?? $mailbox->smtp_password);

        $flags = $encryption === 'ssl' ? '/imap/ssl/novalidate-cert' : '/imap/notls';
        $path = "{{$host}:{$port}{$flags}}INBOX";

        return @imap_open($path, $username, $password, 0, 1);
    }

    private function findMessageInMailbox($imap, string $subject, string $fromEmail): ?int
    {
        $cleanSubject = str_replace(['Re: ', 'RE: ', 'Fwd: ', 'FWD: '], '', $subject);
        $cleanSubject = substr($cleanSubject, 0, 80);

        $results = @imap_search(
            $imap,
            'SUBJECT "' . addcslashes($cleanSubject, '"\\') . '" FROM "' . $fromEmail . '"',
            SE_UID
        );

        if (!$results || empty($results)) {
            $results = @imap_search($imap, 'SUBJECT "' . addcslashes($cleanSubject, '"\\') . '"', SE_UID);
        }

        if ($results && !empty($results)) {
            return (int) end($results);
        }

        return null;
    }

    private function buildMessageBody(string $type, string $from, string $to, int $stepIndex): string
    {
        $human = match ($type) {
            'sender_initial' => 'Initial outreach from sender',
            'seed_reply' => 'Seed mailbox reply',
            'sender_reply' => 'Sender follow-up reply',
            default => 'Flow test interaction',
        };

        return '<p><strong>Flow Test Message</strong></p>'
            . '<p>' . e($human) . '</p>'
            . '<p>From: ' . e($from) . '<br>To: ' . e($to) . '<br>Step: ' . e((string) $stepIndex) . '</p>'
            . '<p style="font-size:12px;color:#777;">This email was generated by the campaign flow tester.</p>';
    }

    private function buildSeedSteps(
        FlowTestRun $run,
        SenderMailbox $sender,
        SeedMailbox $seed,
        int $phaseCount,
        int $openDelaySeconds,
        int $starDelaySeconds,
        int $replyDelaySeconds,
        \Carbon\Carbon $baseTime
    ): array {
        $rows = [];
        $offset = 0;
        $stepIndex = 1;
        $subject = "[FlowTest {$run->id}-{$seed->id}] sender->seed sequence";

        $appendStep = function (string $actionType, int $atOffset, array $payload = []) use (&$rows, &$stepIndex, $run, $seed, $subject, $baseTime): void {
            $rows[] = [
                'flow_test_run_id' => $run->id,
                'seed_mailbox_id' => $seed->id,
                'step_index' => $stepIndex++,
                'action_type' => $actionType,
                'scheduled_at' => $baseTime->copy()->addSeconds($atOffset),
                'status' => 'pending',
                'subject' => $subject,
                'payload' => array_merge(['subject' => $subject], $payload),
            ];
        };

        // Phase 1 baseline: sender send -> seed open -> seed star -> seed reply
        $appendStep('sender_send_initial', $offset, [
            'actor' => 'sender',
            'sender_email' => $sender->email_address,
            'seed_email' => $seed->email_address,
        ]);

        $offset += $openDelaySeconds;
        $appendStep('seed_open_email', $offset, ['actor' => 'seed']);

        if ($starDelaySeconds > 0) {
            $offset += $starDelaySeconds;
            $appendStep('seed_star_message', $offset, ['actor' => 'seed']);
        }

        $offset += $replyDelaySeconds;
        $appendStep('seed_reply', $offset, ['actor' => 'seed']);

        // Additional phases alternate sender/seed open+reply.
        $nextActor = 'sender';
        for ($phase = 2; $phase <= $phaseCount; $phase++) {
            $offset += $openDelaySeconds;
            $appendStep($nextActor . '_open_email', $offset, ['actor' => $nextActor]);

            $offset += $replyDelaySeconds;
            $appendStep($nextActor . '_reply', $offset, ['actor' => $nextActor]);

            $nextActor = $nextActor === 'sender' ? 'seed' : 'sender';
        }

        return $rows;
    }
}
