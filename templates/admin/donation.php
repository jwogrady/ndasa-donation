<?php
/**
 * Admin donation detail view.
 *
 * @var array{
 *   order_id:string,
 *   payment_intent_id:?string,
 *   contact_name:?string,
 *   email:string,
 *   amount_cents:int,
 *   currency:string,
 *   status:string,
 *   created_at:int,
 *   refunded_at:?int,
 *   dedication:?string,
 * } $donation
 * @var string $stripeMode  'live' or 'test'.
 * @var string $appVersion
 */

use NDASA\Support\Html;

$title  = 'Donation ' . substr($donation['order_id'], 0, 8);
$active = 'dashboard';

$pi = $donation['payment_intent_id'] ?? '';
// Stripe dashboard splits live and test; link into the right one so clicks
// don't take an operator from the admin test view to a live PaymentIntent.
$stripePath = $stripeMode === 'test' ? 'test/payments/' : 'payments/';
$stripeUrl  = $pi !== '' ? 'https://dashboard.stripe.com/' . $stripePath . $pi : null;

ob_start();
?>
<h1>Donation detail</h1>

<p class="muted">
  <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin">&larr; Back to dashboard</a>
</p>

<div class="panel">
  <table>
    <tbody>
      <tr>
        <th class="col-check">Order ID</th>
        <td><code><?= Html::h($donation['order_id']) ?></code></td>
      </tr>
      <tr>
        <th>PaymentIntent</th>
        <td>
          <?php if ($stripeUrl !== null): ?>
            <a href="<?= Html::h($stripeUrl) ?>" rel="noopener noreferrer" target="_blank">
              <code><?= Html::h($pi) ?></code>
            </a>
          <?php else: ?>
            <span class="muted">(not recorded)</span>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th>Date</th>
        <td><?= Html::h(date('Y-m-d H:i:s T', $donation['created_at'])) ?></td>
      </tr>
      <tr>
        <th>Donor</th>
        <td>
          <?= Html::h($donation['contact_name'] ?? '—') ?>
          &lt;<a href="mailto:<?= Html::h($donation['email']) ?>"><?= Html::h($donation['email']) ?></a>&gt;
        </td>
      </tr>
      <tr>
        <th>Amount</th>
        <td>
          $<?= Html::h(number_format($donation['amount_cents'] / 100, 2)) ?>
          <?= Html::h(strtoupper($donation['currency'])) ?>
        </td>
      </tr>
      <tr>
        <th>Status</th>
        <td>
          <?php if ($donation['status'] === 'paid'): ?>
            <span class="health-status-ok">PAID</span>
          <?php elseif ($donation['status'] === 'refunded'): ?>
            <span class="health-status-fail">REFUNDED</span>
          <?php else: ?>
            <?= Html::h(strtoupper($donation['status'])) ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($donation['refunded_at'] !== null): ?>
        <tr>
          <th>Refunded</th>
          <td><?= Html::h(date('Y-m-d H:i:s T', $donation['refunded_at'])) ?></td>
        </tr>
      <?php endif; ?>
      <?php if ($donation['dedication'] !== null && $donation['dedication'] !== ''): ?>
        <tr>
          <th>Dedication</th>
          <td><?= Html::h($donation['dedication']) ?></td>
        </tr>
      <?php endif; ?>
      <tr>
        <th>Email updates</th>
        <td>
          <?php if ($donation['email_optin'] === true): ?>
            <span class="health-status-ok">OPTED IN</span>
          <?php elseif ($donation['email_optin'] === false): ?>
            <span class="muted">Opted out</span>
          <?php else: ?>
            <span class="muted">(not recorded)</span>
          <?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<p class="muted fineprint">
  The Stripe dashboard is the authoritative record for financial
  reconciliation. This page reflects the local ledger populated by
  webhook events.
</p>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
