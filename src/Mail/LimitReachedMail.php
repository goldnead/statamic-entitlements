<?php

namespace Goldnead\Entitlements\Mail;

use Goldnead\Entitlements\Integrations\EmailTemplates\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/**
 * "Your limit is reached", already rendered.
 *
 * Built from finished subject and HTML ({@see MailTemplates::render()}),
 * so the text an editor wrote in the email-templates collection is exactly
 * what is sent.
 */
class LimitReachedMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
    ) {}

    public function build(): self
    {
        return $this->subject($this->subjectLine)->html($this->body);
    }
}
