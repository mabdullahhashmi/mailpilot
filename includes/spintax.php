<?php
/**
 * Spintax & Warm-up Content Engine
 * 
 * Handles reading {option1|option2} formats and randomly selecting paths
 * to generate millions of unique business emails for 100% free warm-up.
 */

class SpintaxEngine {
    
    /**
     * Parses a spintax string and returns a random variation
     */
    public static function parse($text) {
        return preg_replace_callback(
            '/\{(((?>[^\{\}]+)|(?R))*)\}/x',
            function ($matches) {
                $text = self::parse($matches[1]);
                $parts = explode('|', $text);
                return $parts[array_rand($parts)];
            },
            $text
        );
    }
    
    /**
     * Generate a full warm-up email
     * Returns array ['subject' => string, 'body' => string]
     */
    public static function generateWarmupEmail() {
        $templates = self::getTemplates();
        $template = $templates[array_rand($templates)];
        
        return [
            'subject' => self::parse($template['subject']),
            'body' => self::parse($template['body'])
        ];
    }
    
    /**
     * Generate an active reply for IMAP
     */
    public static function generateReply() {
        $replies = self::getReplies();
        return self::parse($replies[array_rand($replies)]);
    }

    private static function getTemplates() {
        return [
            [
                'subject' => "{Quick|Brief} {question|inquiry} {about|regarding} your {services|platform|offerings}",
                'body' => "
                    <p>{Hi|Hello|Hey|Greetings} there,</p>
                    <p>{I am|I'm} {reaching out|getting in touch|contacting you} {because|since} {I'm|I am} {interested in|looking to learn more about|researching} your {services|recent updates|platform features}.</p>
                    <p>{Could you|Can you|Would you be able to} {share|send over|provide} some {more details|additional info|pricing information} {when you have a moment|sometime this week}?</p>
                    <p>{Thanks|Thank you|Best|Cheers|Regards},</p>
                "
            ],
            [
                'subject' => "{Checking in|Following up|Touching base} {on our last conversation|regarding my previous email|this week}",
                'body' => "
                    <p>{Hello|Hi},</p>
                    <p>{Hope you are doing well|Hope your week is going great|Trust you are having a good week}.</p>
                    <p>{I just wanted to|I'm writing to} {see if|check whether} you {had time to|managed to} {look over|review} the {documents|info|files} I {sent|shared} earlier?</p>
                    <p>{Let me know|Please advise} if you have any {questions|thoughts|feedback}.</p>
                    <p>{Talk soon|Best regards|Thanks}</p>
                "
            ],
            [
                'subject' => "{Meeting request|Catch up|Feedback request} for {next week|this month|Q3}",
                'body' => "
                    <p>{Good morning|Good afternoon|Hey},</p>
                    <p>{Are you available|Do you have some time|Would you be free} for a {quick call|brief meeting|chat} {sometime soon|next week}?</p>
                    <p>{I'd like to|It would be great to} {discuss|go over|talk about} the {project status|recent changes|upcoming milestones}.</p>
                    <p>{Let me know your availability.|When works best for you?}</p>
                    <p>{Best|Regards|Thanks}</p>
                "
            ]
        ];
    }
    
    private static function getReplies() {
        return [
            "{Thanks for getting back to me|Thank you for the reply}, I will {review this|look into it|check it out} and {get back to you|let you know} {soon|shortly}.",
            "{Got it|Understood|Received}. {Thanks|Thank you} for the {info|update|clarification}.",
            "{Perfect|Great|Sounds good}. {I'll|I will} {reach out|follow up} if I have any {other|more} questions.",
            "{Thanks|Appreciate it}. {Have a great day|Enjoy your week|Talk soon}."
        ];
    }
}
