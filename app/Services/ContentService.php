<?php

namespace App\Services;

use App\Models\ContentTemplate;
use App\Models\Thread;

class ContentService
{
    /**
     * Select an initial email template for the given warmup stage.
     */
    public function selectInitialTemplate(string $stage): ?ContentTemplate
    {
        return ContentTemplate::where('template_type', 'initial')
            ->where('is_active', true)
            ->where(function ($q) use ($stage) {
                $q->where('warmup_stage', $stage)
                  ->orWhere('warmup_stage', 'any');
            })
            ->where(function ($q) {
                $q->whereNull('last_used_at')
                  ->orWhereRaw('last_used_at < DATE_SUB(NOW(), INTERVAL cooldown_minutes MINUTE)');
            })
            ->inRandomOrder()
            ->first();
    }

    /**
     * Select a reply template.
     */
    public function selectReplyTemplate(string $stage, Thread $thread): ?ContentTemplate
    {
        $type = $thread->actual_message_count >= $thread->planned_message_count - 1
            ? 'closing'
            : 'reply';

        return ContentTemplate::where('template_type', $type)
            ->where('is_active', true)
            ->where(function ($q) use ($stage) {
                $q->where('warmup_stage', $stage)
                  ->orWhere('warmup_stage', 'any');
            })
            ->where(function ($q) {
                $q->whereNull('last_used_at')
                  ->orWhereRaw('last_used_at < DATE_SUB(NOW(), INTERVAL cooldown_minutes MINUTE)');
            })
            ->inRandomOrder()
            ->first();
    }

    /**
     * Generate a subject line from template with variation.
     */
    public function generateSubject(?ContentTemplate $template): string
    {
        if (!$template || !$template->subject) {
            $subjects = [
                'Quick question', 'Following up', 'Schedule sync?',
                'Checking in', 'Resource request', 'Brief update',
                'Meeting agenda', 'Project update', 'Quick note',
                'Thought this was relevant', 'Touch base', 'Heads up',
            ];
            return $subjects[array_rand($subjects)];
        }

        return $this->applyVariations($template->subject, $template);
    }

    /**
     * Generate email body from template.
     */
    public function generateBody(?ContentTemplate $template, $sender, $recipient): string
    {
        if (!$template) {
            return $this->generateFallbackBody($sender, $recipient);
        }

        $body = $template->body;

        // Select random greeting
        $greetings = $template->greetings ?? ['Hi', 'Hello', 'Hey'];
        $greeting = $greetings[array_rand($greetings)];

        // Select random signoff
        $signoffs = $template->signoffs ?? ['Best', 'Thanks', 'Regards'];
        $signoff = $signoffs[array_rand($signoffs)];

        // Apply variations
        $body = $this->applyVariations($body, $template);

        // Replace placeholders
        $body = str_replace([
            '{{greeting}}', '{{signoff}}',
            '{{sender_name}}', '{{recipient_name}}',
        ], [
            $greeting, $signoff,
            $this->extractName($sender->email_address),
            $this->extractName($recipient->email_address),
        ], $body);

        // Record usage
        $template->update([
            'usage_count' => $template->usage_count + 1,
            'last_used_at' => now(),
        ]);

        return $body;
    }

    /**
     * Generate reply body.
     */
    public function generateReplyBody(?ContentTemplate $template, $from, $to, $lastMessage): string
    {
        if (!$template) {
            return $this->generateFallbackReply($from, $to);
        }

        $body = $this->generateBody($template, $from, $to);

        // Add quoted previous message
        if ($lastMessage) {
            $body .= "\n\n<blockquote style='border-left:2px solid #ccc;padding-left:10px;margin:10px 0;color:#666;'>"
                   . "On " . ($lastMessage->sent_at?->format('M d, Y') ?? 'earlier') . ", "
                   . "{$to->email_address} wrote:<br>"
                   . substr(strip_tags($lastMessage->body), 0, 200) . "..."
                   . "</blockquote>";
        }

        return $body;
    }

    private function applyVariations(string $text, ContentTemplate $template): string
    {
        $variations = $template->variations ?? [];

        foreach ($variations as $key => $options) {
            if (is_array($options) && !empty($options)) {
                $text = str_replace("{{var:$key}}", $options[array_rand($options)], $text);
            }
        }

        return $text;
    }

    private function extractName(string $email): string
    {
        $local = explode('@', $email)[0];
        $name = str_replace(['.', '_', '-'], ' ', $local);
        return ucwords($name);
    }

    private function generateFallbackBody($sender, $recipient): string
    {
        $greetings = ['Hi', 'Hello', 'Hey'];
        $bodies = [
            "Hope you're doing well. Just wanted to reach out and connect. Let me know if you have a moment to chat.",
            "I wanted to follow up on our previous conversation. Do you have any updates?",
            "Quick question - are you available for a brief call this week? Would love to sync up.",
            "Just checking in to see how things are going on your end. Let me know if you need anything.",
            "I came across something I thought might be of interest. Happy to share more details if you're interested.",
        ];
        $signoffs = ['Best', 'Thanks', 'Regards', 'Cheers'];

        $greeting = $greetings[array_rand($greetings)];
        $body = $bodies[array_rand($bodies)];
        $signoff = $signoffs[array_rand($signoffs)];
        $name = $this->extractName($sender->email_address);

        return "<p>{$greeting},</p><p>{$body}</p><p>{$signoff},<br>{$name}</p>";
    }

    private function generateFallbackReply($from, $to): string
    {
        $replies = [
            "Thanks for getting back to me! That sounds great.",
            "Appreciate the update. Let me review and get back to you.",
            "Got it, thanks! I'll follow up shortly.",
            "That works for me. Let's plan on that.",
            "Great, thanks for letting me know. Talk soon!",
        ];
        $signoffs = ['Best', 'Thanks', 'Cheers'];

        $reply = $replies[array_rand($replies)];
        $signoff = $signoffs[array_rand($signoffs)];
        $name = $this->extractName($from->email_address);

        return "<p>{$reply}</p><p>{$signoff},<br>{$name}</p>";
    }
}
