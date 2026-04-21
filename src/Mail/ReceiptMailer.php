<?php
declare(strict_types=1);

namespace NDASA\Mail;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final class ReceiptMailer
{
    private Mailer $mailer;

    public function __construct(?string $dsn = null)
    {
        $dsn ??= $_ENV['SMTP_DSN'] ?? '';
        if ($dsn === '') {
            throw new \RuntimeException('SMTP_DSN not configured.');
        }
        $this->mailer = new Mailer(Transport::fromDsn($dsn));
    }

    /** @param array{order_id:string,amount_cents:int,currency:string,email:string,name:string} $d */
    public function sendInternalNotification(array $d): void
    {
        $fromAddr = $_ENV['MAIL_FROM'] ?? '';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'NDASA Foundation';
        $to       = $_ENV['MAIL_BCC_INTERNAL'] ?? '';

        if ($fromAddr === '' || $to === '') {
            throw new \RuntimeException('MAIL_FROM / MAIL_BCC_INTERNAL not configured.');
        }

        // Defence in depth; Symfony Mailer already rejects these, but be explicit.
        foreach (['email', 'name'] as $k) {
            if (preg_match('/[\r\n]/', (string) $d[$k])) {
                throw new \InvalidArgumentException("Invalid char in {$k}");
            }
        }

        $amountUsd = number_format($d['amount_cents'] / 100, 2);
        $currency  = strtoupper($d['currency']);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromAddr))
            ->to($to)
            ->subject('New NDASA donation: $' . $amountUsd)
            ->text(
                "A new donation was received.\n\n" .
                "Order ID: {$d['order_id']}\n" .
                "Amount:   \${$amountUsd} {$currency}\n" .
                "Donor:    {$d['name']} <{$d['email']}>\n"
            );

        $this->mailer->send($email);
    }
}
