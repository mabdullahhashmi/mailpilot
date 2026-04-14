<?php

namespace App\Services;

use App\Models\ContentTemplate;
use App\Models\Thread;

class ContentService
{
    private SpintaxProcessor $spintax;
    private IntentDetector $intent;

    public function __construct()
    {
        $this->spintax = new SpintaxProcessor();
        $this->intent = new IntentDetector();
    }

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
     * Select a reply template based on intent detected from the last message.
     */
    public function selectReplyTemplate(string $stage, Thread $thread, ?string $lastMessageBody = null): ?ContentTemplate
    {
        $type = $thread->actual_message_count >= $thread->planned_message_count - 1
            ? 'closing'
            : 'reply';

        // Detect intent from last message
        $intentCategory = $this->intent->detect($lastMessageBody);

        // Try intent-matched category first
        if ($intentCategory !== 'generic') {
            $template = ContentTemplate::where('template_type', $type)
                ->where('is_active', true)
                ->where('category', $intentCategory)
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

            if ($template) {
                return $template;
            }
        }

        // Fallback: try related intents
        $relatedIntents = $this->intent->detectMultiple($lastMessageBody);
        foreach (array_keys($relatedIntents) as $relatedCategory) {
            if ($relatedCategory === $intentCategory || $relatedCategory === 'generic') continue;

            $template = ContentTemplate::where('template_type', $type)
                ->where('is_active', true)
                ->where('category', $relatedCategory)
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

            if ($template) {
                return $template;
            }
        }

        // Final fallback: any template of the right type
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
     * Generate a subject line from template with spintax processing.
     */
    public function generateSubject(?ContentTemplate $template): string
    {
        if (!$template || !$template->subject) {
            $subjects = [
                '{Quick question|Brief question|Something quick} {about your site|regarding your pages|about web optimization}',
                '{Following up|Circling back|Checking in} on {our conversation|the discussion|what we talked about}',
                '{Landing page|Website|Web design} {ideas|thoughts|insights} {for you|worth sharing|to consider}',
                '{Conversion|Performance|Optimization} {tip|insight|strategy} — {thought of you|wanted to share|quick share}',
                '{Website audit|Page review|Quick analysis} — {interesting findings|some thoughts|worth a look}',
                '{Page speed|Load time|Performance} {matters|is everything|makes the difference} — {quick note|brief thought|heads up}',
            ];
            $raw = $subjects[array_rand($subjects)];
            return $this->spintax->process($raw);
        }

        $subject = $template->subject;
        $subject = $this->applyVariations($subject, $template);
        $subject = $this->spintax->process($subject);

        return $this->sanitizeResidualTemplateTokens($subject);
    }

    /**
     * Generate email body from template with spintax + variations.
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

        $senderName = $this->extractName($sender->email_address);
        $recipientName = $this->extractName($recipient->email_address);

        // Apply template variations before token replacement.
        $body = $this->applyVariations($body, $template);

        // Replace placeholders (supports both {{name}} and {{ name }} forms).
        $body = $this->replaceCorePlaceholders($body, [
            'greeting' => $greeting,
            'signoff' => $signoff,
            'sender_name' => $senderName,
            'recipient_name' => $recipientName,
        ]);

        // Process spintax after placeholders are resolved.
        $body = $this->spintax->process($body);

        // Safety net: never allow raw template codes to leak into outbound body.
        $body = $this->sanitizeResidualTemplateTokens($body, [
            'greeting' => $greeting,
            'signoff' => $signoff,
            'sender_name' => $senderName,
            'recipient_name' => $recipientName,
        ]);

        // Record usage
        $template->update([
            'usage_count' => $template->usage_count + 1,
            'last_used_at' => now(),
        ]);

        return $body;
    }

    /**
     * Generate reply body with intent-aware content and spintax.
     */
    public function generateReplyBody(?ContentTemplate $template, $from, $to, $lastMessage): string
    {
        if (!$template) {
            return $this->generateFallbackReply($from, $to, $lastMessage);
        }

        $body = $this->generateBody($template, $from, $to);

        // Add quoted previous message for realism
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
                $replacement = $options[array_rand($options)];
                $pattern = '/\{\{\s*var:' . preg_quote((string) $key, '/') . '\s*\}\}/i';
                $text = preg_replace($pattern, (string) $replacement, $text);
            }
        }

        // Replace unresolved variation tokens with a humanized fallback.
        $text = preg_replace_callback('/\{\{\s*var:([a-z0-9_]+)\s*\}\}/i', function ($matches) {
            $key = strtolower((string) ($matches[1] ?? ''));
            return ucwords(str_replace('_', ' ', $key));
        }, $text);

        return $text;
    }

    private function replaceCorePlaceholders(string $text, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $pattern = '/\{\{\s*' . preg_quote((string) $key, '/') . '\s*\}\}/i';
            $text = preg_replace($pattern, (string) $value, $text);
        }

        return $text;
    }

    private function sanitizeResidualTemplateTokens(string $text, array $fallbacks = []): string
    {
        $fallbackGreeting = (string) ($fallbacks['greeting'] ?? 'Hi');
        $fallbackSignoff = (string) ($fallbacks['signoff'] ?? 'Best');
        $fallbackSenderName = (string) ($fallbacks['sender_name'] ?? 'Team');
        $fallbackRecipientName = (string) ($fallbacks['recipient_name'] ?? 'there');

        // Handle legacy/plain placeholders that may have leaked from prior rendering bugs.
        $text = preg_replace('/\bsender_name\b/i', $fallbackSenderName, $text);
        $text = preg_replace('/\brecipient_name\b/i', $fallbackRecipientName, $text);

        // Replace line-leading "greeting," / "signoff," style placeholders.
        $text = preg_replace('/(^|\n)\s*greeting\s*([,:\-])/i', '$1' . $fallbackGreeting . '$2', $text);
        $text = preg_replace('/(^|\n)\s*signoff\s*([,:\-])/i', '$1' . $fallbackSignoff . '$2', $text);

        // Remove any remaining unresolved mustache tokens.
        $text = preg_replace('/\{\{\s*[^}]+\s*\}\}/', '', $text);

        // Clean extra spaces introduced by token removal.
        $text = preg_replace('/\s{2,}/', ' ', $text);
        $text = preg_replace('/\s([.,!?;:])/', '$1', $text);

        return trim((string) $text);
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
            "{I came across your website|I noticed your site|I was browsing your pages} and {had a few thoughts|saw some potential|thought I'd reach out}. {Landing page optimization|Website performance|Conversion optimization} is {something I focus on|my area of expertise|what I specialize in} and I'd {love to share some insights|be happy to help|enjoy chatting about it}.",
            "{Just wanted to connect|Reaching out|Thought I'd say hello} — I've been {working on landing page projects|helping businesses optimize their pages|doing web optimization work} lately and {thought of you|your site came to mind|wanted to touch base}.",
            "{Quick note|Brief message|Short hello} — I {recently completed|just finished|wrapped up} a {landing page redesign|conversion optimization project|website rebuild} and {the results were promising|it went really well|the client was thrilled}. {Would love to chat|Happy to share details|Let me know if you'd like to hear more}.",
            "{Hope you're doing well|Hope things are going great|Checking in}. I've been {diving deep into|researching|exploring} {page speed optimization|A/B testing strategies|conversion rate techniques} and {found some interesting approaches|discovered something useful|learned a few things worth sharing}.",
            "{Wanted to reach out|Dropping a quick line|Brief hello} about {web design trends|landing page best practices|site optimization insights} I've been {noticing lately|working with|implementing for clients}. {Always open to discuss|Happy to chat|Would enjoy connecting}.",
        ];
        $signoffs = ['Best', 'Thanks', 'Regards', 'Cheers'];

        $greeting = $greetings[array_rand($greetings)];
        $body = $this->spintax->process($bodies[array_rand($bodies)]);
        $signoff = $signoffs[array_rand($signoffs)];
        $name = $this->extractName($sender->email_address);

        return "<p>{$greeting},</p><p>{$body}</p><p>{$signoff},<br>{$name}</p>";
    }

    private function generateFallbackReply($from, $to, $lastMessage = null): string
    {
        // Detect intent from last message for contextual reply
        $intent = 'generic';
        if ($lastMessage && $lastMessage->body) {
            $intent = $this->intent->detect($lastMessage->body);
        }

        $replyMap = [
            'question' => [
                "{Great question|Good question|That's a great point}! {From what I've seen|In my experience|Based on my work}, {landing pages with clear CTAs convert best|optimizing load times makes a huge difference|the design really matters for conversions}. {Happy to dive deeper|Let me know if you want details|We could discuss further}.",
                "{To answer your question|Regarding that|On that topic} — {it depends on the specific setup|there are a few approaches|I've seen different solutions work}. {For landing pages specifically|When it comes to web optimization|In terms of conversions}, {I'd recommend a thorough audit first|starting with data is key|testing is essential}.",
            ],
            'pricing' => [
                "{Good question about pricing|Thanks for asking about rates|Appreciate you bringing that up}. {It really depends on the scope|Every project is different|I tailor pricing to the project}. {For landing page optimization|For web development work|For conversion projects}, {I usually start with an assessment|a quick audit helps scope things|we'd want to review the current setup first}.",
                "{Pricing varies|Rates depend on scope|Cost is project-specific} but {I always aim for great value|I focus on ROI|I make sure the investment pays off}. {Landing page projects|Website optimization|Conversion work} {typically shows results quickly|has strong ROI|pays for itself}.",
            ],
            'timeline' => [
                "{Timeline depends on the project scope|Turnaround varies by complexity|It depends on what's needed}. {Most landing page projects|Typical optimization work|Standard builds} take {a few weeks|2-4 weeks|about a month}. {Happy to discuss specifics|Let's chat about your timeline|We can figure out a schedule that works}.",
                "{Great question about timing|Good to plan ahead|Let's talk timeline}. {I usually work efficiently|I focus on quick turnaround|I aim for fast delivery} but {quality always comes first|I never rush quality|thorough work takes proper time}.",
            ],
            'technical' => [
                "{That's a solid technical point|Great technical question|Good observation}. {For landing pages|When it comes to web optimization|In terms of performance}, {page speed is critical|mobile responsiveness matters|CTA placement is key}. {I've been running tests on this|I've seen interesting data|My recent projects confirm this}.",
                "{From a technical perspective|Looking at the technical side|On the technical front}, {there are proven approaches|best practices really help|the data is clear}. {A/B testing|Speed optimization|UX improvements} {consistently show results|make a measurable difference|drive conversions}.",
            ],
            'thanks' => [
                "{Glad that was helpful|Happy to help|Anytime}! {Let me know if anything else comes up|Feel free to reach out anytime|Always here if you need anything}. {Enjoy the rest of your day|Talk soon|Hope it goes well}.",
                "{You're welcome|Of course|My pleasure}! {Web optimization is one of those things|Landing page work|This kind of project} that {really pays off when done right|makes a real difference|brings solid returns}. {Keep me posted|Let me know how it goes|Happy to help anytime}.",
            ],
            'follow_up' => [
                "{Just circling back|Wanted to follow up|Checking in} on {our previous conversation|what we discussed|the topic we talked about}. {Still think there's potential|I'm still interested in connecting|Would love to continue the discussion}. {Let me know your thoughts|Whenever you have a moment|No rush at all}.",
                "{Following up|Touching base|Quick check-in} — {hope things are going well|hope you're doing great|been thinking about our chat}. {Landing page optimization|Website improvement|Conversion work} is {still on my mind|something I keep coming back to|a passion of mine}. {Just wanted to reconnect|Open to chatting more|Let me know if the timing is better now}.",
            ],
            'availability' => [
                "{I'm available|I'm free|I have time} {this week|to chat|for a quick call}. {Would love to discuss|Happy to connect|Let's find a time}. {What works for you|When are you free|Pick a time that suits you}?",
                "{Let's find a time|Happy to schedule something|Would enjoy connecting}. {I'm flexible this week|My calendar is open|I can work around your schedule}. {A quick 15-minute chat|A short call|A brief discussion} {could be really productive|would be great|sounds ideal}.",
            ],
            'agreement' => [
                "{Great to hear|Awesome|Perfect}! {Let's make it happen|Looking forward to it|Excited to move forward}. {I'll put some thoughts together|Let me prepare a few ideas|I'll get started on some notes}. {Talk soon|We'll connect shortly|Looking forward to next steps}.",
                "{Sounds like a plan|That works perfectly|Excellent}! {Landing page optimization|Web projects|Conversion work} is {always exciting to dig into|something I enjoy|rewarding work}. {Let's get the ball rolling|I'll follow up with details|Expect to hear from me soon}.",
            ],
            'objection' => [
                "{Totally understand|No worries at all|Completely fair}. {There's no pressure|Take your time|Whenever you're ready}. {Web optimization|Landing page work|This kind of thing} {can wait for the right moment|works best when timing is right|is always available when you need it}. {Feel free to reach out anytime|My door is always open|Just let me know}.",
                "{Makes complete sense|I get that|Appreciate your honesty}. {Timing is everything|No rush|It'll be here when you're ready}. {If things change|When the time is right|Whenever you want to revisit}, {just reach out|don't hesitate to connect|I'm here}.",
            ],
            'generic' => [
                "{Appreciate the response|Thanks for getting back|Good to hear from you}. {That's an interesting point|I hadn't thought of it that way|Makes sense}. {Let me know if you want to chat more|Happy to continue the conversation|We should keep this going}.",
                "{Thanks for the note|Good to connect|Appreciate you reaching out}. {Landing page optimization|Website work|Web development} is {always evolving|constantly improving|an exciting space}. {Let me know your thoughts|Would enjoy discussing further|Happy to chat anytime}.",
            ],
        ];

        $replies = $replyMap[$intent] ?? $replyMap['generic'];
        $reply = $this->spintax->process($replies[array_rand($replies)]);
        $signoffs = ['Best', 'Thanks', 'Cheers'];
        $signoff = $signoffs[array_rand($signoffs)];
        $name = $this->extractName($from->email_address);

        return "<p>{$reply}</p><p>{$signoff},<br>{$name}</p>";
    }
}
