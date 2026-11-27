<?php

namespace App\Services;

use App\Exceptions\EmailException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one plain email (#93's services/email_sender.py). The only class that
 * knows which email service is used; EMAIL_PROVIDER picks it:
 *   console  write the message to the log instead (local runs, CI)
 *   brevo    Brevo's transactional HTTPS API
 *
 * Why an HTTPS API and not SMTP: Render's free plan blocks outgoing SMTP ports.
 * Used through the container (app(EmailSender::class)), so tests can record
 * the emails instead of sending them.
 */
class EmailSender
{
    public const BREVO_URL = 'https://api.brevo.com/v3/smtp/email';

    /** @throws EmailException the email couldn't be handed to the provider (the caller answers 503) */
    public function send(string $to, string $subject, string $text): void
    {
        $provider = strtolower(trim((string) config('timetable.email.provider')));
        match ($provider) {
            'console' => Log::warning("EMAIL (console, not sent) to=$to subject=$subject\n$text"),
            'brevo' => $this->sendBrevo($to, $subject, $text),
            default => throw new EmailException("Unknown EMAIL_PROVIDER '$provider'"),
        };
    }

    private function sendBrevo(string $to, string $subject, string $text): void
    {
        $key = config('timetable.email.brevo_api_key');
        $from = config('timetable.email.from');
        if (! $key || ! $from) {
            throw new EmailException('EMAIL_PROVIDER=brevo needs BREVO_API_KEY and EMAIL_FROM');
        }

        try {
            $res = Http::withHeaders(['api-key' => $key, 'accept' => 'application/json'])->timeout(10)
                ->post(self::BREVO_URL, [
                    'sender' => ['email' => $from, 'name' => config('timetable.email.from_name')],
                    'to' => [['email' => $to]],
                    'subject' => $subject,
                    'textContent' => $text,
                ]);
        } catch (ConnectionException $e) {
            throw new EmailException('Brevo unreachable: '.$e->getMessage(), 0, $e);
        }
        if ($res->status() >= 300) {
            // Brevo's body says why (e.g. "sender not verified"); log it, never the key.
            Log::error('Brevo refused the email: '.$res->status().' '.substr($res->body(), 0, 300));
            throw new EmailException('Brevo answered '.$res->status());
        }
    }
}
