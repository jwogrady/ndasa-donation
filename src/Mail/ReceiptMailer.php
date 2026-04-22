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
use Symfony\Component\Mime\Email;

final class ReceiptMailer
{
    private Mailer $mailer;

    public function __construct(?string $dsn = null)
    {
        // Explicit DSN wins. Otherwise prefer SMTP_* / SMTP_DSN env config, and
        // fall back to the local MTA via Symfony's sendmail transport so the
        // webhook is never gated on a deliverable SMTP account. The sendmail
        // path is what PHP's native mail() uses under the hood on Nexcess.
        $this->mailer = new Mailer(
            $dsn !== null
                ? Transport::fromDsn($dsn)
                : Transport::fromDsn(self::transportDsnFromEnv()),
        );
    }

    /**
     * Resolve a transport DSN from env, preferring (in order):
     *   1. SMTP_DSN (pre-formed)
     *   2. SMTP_HOST + friends (discrete SMTP_*)
     *   3. sendmail://default — the local MTA, always present on Nexcess.
     *
     * Returning the sendmail fallback instead of throwing means a missing
     * SMTP config degrades to "delivered via the MX of the server" rather
     * than taking the caller (webhook) out.
     */
    private static function transportDsnFromEnv(): string
    {
        $preformed = (string) ($_ENV['SMTP_DSN'] ?? '');
        if ($preformed !== '') {
            return $preformed;
        }

        $host = (string) ($_ENV['SMTP_HOST'] ?? '');
        if ($host === '') {
            return 'sendmail://default';
        }

        $port = (int) ($_ENV['SMTP_PORT'] ?? 587);
        $user = (string) ($_ENV['SMTP_USERNAME'] ?? '');
        $pass = (string) ($_ENV['SMTP_PASSWORD'] ?? '');
        $enc  = strtolower((string) ($_ENV['SMTP_ENCRYPTION'] ?? 'tls'));

        // Symfony's smtp scheme = STARTTLS; smtps scheme = implicit TLS.
        $scheme = $enc === 'ssl' ? 'smtps' : 'smtp';

        $auth = '';
        if ($user !== '') {
            $auth = rawurlencode($user);
            if ($pass !== '') {
                $auth .= ':' . rawurlencode($pass);
            }
            $auth .= '@';
        }
        return sprintf('%s://%s%s:%d', $scheme, $auth, $host, $port);
    }

    /** @param array{order_id:string,amount_cents:int,currency:string,email:string,name:string,dedication?:string} $d */
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

        $body = "A new donation was received.\n\n" .
                "Order ID: {$d['order_id']}\n" .
                "Amount:   \${$amountUsd} {$currency}\n" .
                "Donor:    {$d['name']} <{$d['email']}>\n";

        $dedication = (string) ($d['dedication'] ?? '');
        if ($dedication !== '') {
            $body .= "Dedication: {$dedication}\n";
        }

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromAddr))
            ->to($to)
            ->subject('New NDASA donation: $' . $amountUsd)
            ->text($body);

        $this->mailer->send($email);
    }
}
