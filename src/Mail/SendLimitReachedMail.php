<?php

namespace Goldnead\Entitlements\Mail;

use Goldnead\Entitlements\Events\LimitReached;
use Goldnead\Entitlements\Integrations\EmailTemplates\MailTemplates;
use Goldnead\Entitlements\Limits\LimitCatalog;
use Goldnead\Entitlements\Support\SubjectContacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends "limit reached" when the operator has switched it on.
 *
 * Not in `src/Listeners/`: core wires that directory by reflection in the
 * booted phase, and the provider registers this one explicitly. Both would
 * mean two mails.
 *
 * Recipients: the person who hit the limit, by default. A host that wants the
 * team's owner told as well (or instead) registers a resolver with
 * `Entitlements::mailRecipientsUsing()`. A recipient without an address is
 * logged and skipped. Sent after the commit and never thrown: a mail server
 * that is down must not cost the booking that reached the limit.
 */
class SendLimitReachedMail
{
    /** @var (callable(LimitReached): iterable<string|array{email: string, name?: string|null}>)|null */
    private static $recipients = null;

    /** @param  (callable(LimitReached): iterable<string|array{email: string, name?: string|null}>)|null  $resolver */
    public static function recipientsUsing(?callable $resolver): void
    {
        self::$recipients = $resolver;
    }

    public function handle(LimitReached $event): void
    {
        if (! config('entitlements.mail.limit_reached.enabled', false)) {
            return;
        }

        try {
            DB::afterCommit(fn () => $this->send($event));
        } catch (Throwable $e) {
            $this->fail($event, $e);
        }
    }

    private function send(LimitReached $event): void
    {
        try {
            foreach ($this->recipients($event) as $recipient) {
                $rendered = MailTemplates::render('limit_reached', $this->variables($event, $recipient['name']));

                Mail::to($recipient['email'], $recipient['name'])
                    ->send(new LimitReachedMail($rendered['subject'], $rendered['html']));
            }
        } catch (Throwable $e) {
            $this->fail($event, $e);
        }
    }

    /**
     * @return list<array{email: string, name: string|null}>
     */
    private function recipients(LimitReached $event): array
    {
        $raw = self::$recipients !== null
            ? (self::$recipients)($event)
            : array_filter([SubjectContacts::for($event->subject)]);

        $recipients = [];

        foreach ($raw as $entry) {
            $email = is_array($entry) ? ($entry['email'] ?? null) : $entry;
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;

            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($email)] = ['email' => $email, 'name' => is_string($name) ? $name : null];
            }
        }

        if ($recipients === []) {
            Log::info('statamic-entitlements: limit reached, but nobody to write to.', [
                'subject' => $event->subject->key(),
                'key' => $event->key,
            ]);
        }

        return array_values($recipients);
    }

    /** @return array<string, mixed> */
    public function variables(LimitReached $event, ?string $name = null): array
    {
        $label = app(LimitCatalog::class)->label($event->key);
        $end = $event->periodEnd
            ? $event->periodEnd->setTimezone((string) config('app.timezone', 'UTC'))->translatedFormat('j. F Y')
            : '';

        return [
            // One sentence that fits both kinds: a usage limit comes back with
            // the next period, a stock limit when something is removed.
            'period_note' => $end !== ''
                ? (string) __('entitlements::mail.limit_reached.note_usage', ['date' => $end])
                : (string) __('entitlements::mail.limit_reached.note_stock'),
            'name' => $name ?? '',
            'limit_key' => $event->key,
            'limit_label' => $label,
            'limit' => $event->limit,
            'used' => $event->used,
            'product' => $event->product ?? '',
            'period_end' => $end,
            'app_name' => (string) config('app.name'),
        ];
    }

    private function fail(LimitReached $event, Throwable $e): void
    {
        Log::warning('statamic-entitlements: the "limit reached" mail could not be sent.', [
            'subject' => $event->subject->key(),
            'key' => $event->key,
            'exception' => $e->getMessage(),
        ]);
    }
}
