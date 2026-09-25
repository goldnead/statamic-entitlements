<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Integrations\EmailTemplates\MailTemplates;
use Goldnead\Entitlements\Mail\LimitReachedMail;
use Goldnead\Entitlements\Mail\SendLimitReachedMail;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Mail;

/**
 * „Grenze erreicht" als Mail. Aus, bis jemand sie einschaltet: dieses Addon hat
 * bisher nichts verschickt, und ein Update darf nicht von sich aus anfangen.
 * Hier ohne email-templates (Brücke aus), also über die mitgelieferte Vorlage;
 * der Weg über email-templates steht in tests/Integration.
 */
beforeEach(function () {
    config()->set('entitlements.bridges.email_templates', false);
    config()->set('entitlements.limits.keys', ['analyses' => ['label' => 'Tempo-Analysen', 'period' => 'year']]);
    app()->setLocale('de');

    Entitlements::setLimits('solo', ['analyses' => 2]);
    $this->anna = new SubjectReference('email', 'anna@example.test');
    Entitlements::grant($this->anna, 'solo', 'manual');

    Mail::fake();
});

afterEach(fn () => SendLimitReachedMail::recipientsUsing(null));

it('sends nothing while it is switched off', function () {
    Entitlements::consume($this->anna, 'analyses', 2);

    Mail::assertNothingSent();
});

it('writes to the person who reached the limit, once', function () {
    config()->set('entitlements.mail.limit_reached.enabled', true);

    Entitlements::consume($this->anna, 'analyses');
    Mail::assertNothingSent();

    Entitlements::consume($this->anna, 'analyses');
    Entitlements::consume($this->anna, 'analyses');

    Mail::assertSent(LimitReachedMail::class, 1);
    Mail::assertSent(LimitReachedMail::class, function (LimitReachedMail $mail) {
        return $mail->hasTo('anna@example.test')
            && $mail->subjectLine === 'Tempo-Analysen: Grenze erreicht'
            && str_contains($mail->body, 'die Grenze deines Zugangs erreicht: 2 von 2')
            && str_contains($mail->body, 'steht dir das Kontingent wieder zur Verfügung');
    });
});

it('lets the host name the recipients, for a team\'s owner', function () {
    config()->set('entitlements.mail.limit_reached.enabled', true);
    Entitlements::mailRecipientsUsing(fn () => [['email' => 'leitung@example.test', 'name' => 'Chorleitung']]);

    Entitlements::consume($this->anna, 'analyses', 2);

    Mail::assertSent(LimitReachedMail::class, fn (LimitReachedMail $m) => $m->hasTo('leitung@example.test')
        && str_contains($m->body, 'Hallo Chorleitung'));
    Mail::assertNotSent(LimitReachedMail::class, fn (LimitReachedMail $m) => $m->hasTo('anna@example.test'));
});

it('escapes what it puts into the body', function () {
    $html = MailTemplates::merge('<p>{{ name }}</p>', ['name' => '<script>x</script>']);

    expect($html)->toBe('<p>&lt;script&gt;x&lt;/script&gt;</p>');
});

it('never lets a broken mailer cost the booking', function () {
    config()->set('entitlements.mail.limit_reached.enabled', true);
    Entitlements::mailRecipientsUsing(fn () => throw new RuntimeException('Postfach weg'));

    expect(Entitlements::consume($this->anna, 'analyses', 2))->toBeTrue();
});
