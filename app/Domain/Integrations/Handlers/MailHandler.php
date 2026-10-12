<?php

namespace App\Domain\Integrations\Handlers;

use App\Domain\Integrations\ActionException;
use App\Domain\Integrations\ActionResult;
use App\Domain\Integrations\Models\Credential;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends one email through the SMTP server stored in the node's credential.
 */
class MailHandler
{
    public function handle(string $handler, array $inputs, ?Credential $credential): ActionResult
    {
        if (! $credential || $credential->auth_type !== 'smtp') {
            throw new ActionException('Select an SMTP credential for this node.');
        }

        $smtp = $credential->data;
        $encryption = $smtp['encryption'] ?? 'tls';

        // "ssl" = implicit TLS (465); "tls" = STARTTLS required; "none" = plain.
        $transport = new EsmtpTransport($smtp['host'], (int) ($smtp['port'] ?? 587), $encryption === 'ssl');
        if ($encryption === 'tls') {
            $transport->setRequireTls(true);
        } elseif ($encryption === 'none') {
            $transport->setAutoTls(false);
        }
        if (! empty($smtp['username'])) {
            $transport->setUsername($smtp['username']);
            $transport->setPassword((string) ($smtp['password'] ?? ''));
        }

        $email = (new Email)
            ->from(new Address($smtp['from_address'], (string) ($smtp['from_name'] ?? '')))
            ->to(...$this->addresses($inputs['to']))
            ->subject((string) $inputs['subject']);

        if ($cc = $this->addresses($inputs['cc'] ?? null)) {
            $email->cc(...$cc);
        }
        if ($bcc = $this->addresses($inputs['bcc'] ?? null)) {
            $email->bcc(...$bcc);
        }
        if (! empty($inputs['reply_to'])) {
            $email->replyTo(...$this->addresses($inputs['reply_to']));
        }

        ($inputs['format'] ?? 'html') === 'text'
            ? $email->text((string) $inputs['body'])
            : $email->html((string) $inputs['body'])->text(trim(strip_tags((string) $inputs['body'])));

        try {
            $sent = $transport->send($email);
        } catch (Throwable $e) {
            throw new ActionException('SMTP error: '.$e->getMessage(), previous: $e);
        } finally {
            $transport->stop();
        }

        return new ActionResult(output: [
            'message_id' => $sent?->getMessageId(),
            'to' => array_map(fn (Address $a) => $a->getAddress(), $email->getTo()),
            'subject' => $email->getSubject(),
        ]);
    }

    /** @return list<Address> */
    private function addresses(mixed $value): array
    {
        $list = is_array($value) ? $value : preg_split('/[,;]+/', (string) $value);

        return array_values(array_map(function ($address) {
            try {
                return Address::create(trim($address));
            } catch (Throwable) {
                throw new ActionException("Invalid email address [$address].");
            }
        }, array_filter(array_map('trim', $list))));
    }
}
