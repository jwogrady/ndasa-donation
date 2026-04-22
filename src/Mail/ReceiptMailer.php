<?php
/**
 * NDASA Donation Platform
 *
 * @package    NDASA\Donation
 * @author     William Cross
 * @author     John O'Grady <john@status26.com>
 * @copyright  2026 NDASA Foundation
 * @license    Proprietary - NDASA Foundation
 * @link       https://ndasafoundation.org/
 *
 * Maintained in honor of William Cross.
 */
declare(strict_types=1);

namespace NDASA\Mail;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

final class ReceiptMailer
{
    private Mailer $mailer;

    public function __construct(?string $dsn = null)
    {
        $this->mailer = new Mailer(
            $dsn !== null
                ? Transport::fromDsn($dsn)
                : Transport::fromDsnObject(self::dsnFromEnv()),
        );
    }

    /**
     * Prefer discrete SMTP_* components (safe with any password charset);
     * fall back to a pre-formed SMTP_DSN for callers that provide one.
     */
    private static function dsnFromEnv(): Dsn
    {
        $preformed = (string) ($_ENV['SMTP_DSN'] ?? '');
        if ($preformed !== '') {
            return Dsn::fromString($preformed);
        }

        $host = (string) ($_ENV['SMTP_HOST'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('SMTP not configured (set SMTP_HOST or SMTP_DSN).');
        }

        $port = (int) ($_ENV['SMTP_PORT'] ?? 587);
        $user = (string) ($_ENV['SMTP_USERNAME'] ?? '');
        $pass = (string) ($_ENV['SMTP_PASSWORD'] ?? '');
        $enc  = strtolower((string) ($_ENV['SMTP_ENCRYPTION'] ?? 'tls'));

        // Symfony's smtp scheme = STARTTLS; smtps scheme = implicit TLS.
        $scheme = $enc === 'ssl' ? 'smtps' : 'smtp';

        return new Dsn(
            scheme:   $scheme,
            host:     $host,
            user:     $user !== '' ? $user : null,
            password: $pass !== '' ? $pass : null,
            port:     $port,
        );
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
