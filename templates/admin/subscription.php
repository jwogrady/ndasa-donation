<?php
/**
 * Subscription detail view.
 *
 * @var string $subId
 * @var list<array{order_id:string,payment_intent_id:?string,amount_cents:int,currency:string,status:string,created_at:int,refunded_at:?int}> $invoices
 * @var ?string $liveStatus    Authoritative status from Stripe API, or null if unreachable.
 * @var ?array{current_period_start:int,current_period_end:int,cancel_at:?int,cancel_at_period_end:bool} $liveDetails
 * @var string $stripeMode
 * @var string $appVersion
 */

use NDASA\Support\Html;

$title  = 'Subscription ' . substr($subId, 0, 12);
$active = 'subscriptions';

$fmtMoney = static fn (int $c, string $cur): string => '$' . number_format($c / 100, 2) . ' ' . strtoupper($cur);
$prefix   = 'https://dashboard.stripe.com/' . ($stripeMode === 'test' ? 'test/' : '');
$stripeUrl = $prefix . 'subscriptions/' . $subId;

// Compute totals from the local ledger so the page shows a revenue view
// alongside Stripe's live status.
$paidCents = 0;
$latestCurrency = 'USD';
foreach ($invoices as $inv) {
    if ($inv['status'] === 'paid') {
        $paidCents += $inv['amount_cents'];
    }
    $latestCurrency = $inv['currency'];
}

ob_start();
?>
<h1>Subscription</h1>
<p class="muted">
  <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin/subscriptions">&larr; All subscriptions</a>
</p>

<div class="panel">
  <dl>
    <dt>Subscription ID</dt>
    <dd>
      <code><?= Html::h($subId) ?></code>
      &middot;
      <a href="<?= Html::h($stripeUrl) ?>" target="_blank" rel="noopener noreferrer">View on Stripe &rarr;</a>
    </dd>
    <dt>Live status</dt>
    <dd>
      <?php if ($liveStatus !== null): ?>
        <strong><?= Html::h($liveStatus) ?></strong>
        <?php if ($liveDetails !== null): ?>
          <?php if ($liveDetails['cancel_at_period_end']): ?>
            &middot; cancels at period end
          <?php elseif ($liveDetails['cancel_at'] !== null): ?>
            &middot; cancels <?= Html::h(date('Y-m-d', $liveDetails['cancel_at'])) ?>
          <?php endif; ?>
          <?php if ($liveDetails['current_period_end'] > 0): ?>
            <span class="muted">
              (period ends <?= Html::h(date('Y-m-d', $liveDetails['current_period_end'])) ?>)
            </span>
          <?php endif; ?>
        <?php endif; ?>
      <?php else: ?>
        <span class="muted">Stripe unreachable — live status unavailable.</span>
      <?php endif; ?>
    </dd>
    <dt>Lifetime paid</dt>
    <dd><?= Html::h($fmtMoney($paidCents, $latestCurrency)) ?></dd>
    <dt>Invoices recorded</dt>
    <dd><?= Html::h((string) count($invoices)) ?></dd>
  </dl>
</div>

<h2>Invoices</h2>
<div class="panel">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Amount</th>
        <th>Status</th>
        <th>Order</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($invoices as $inv):
        $detailUrl = Html::h(NDASA_BASE_PATH) . '/admin/donations/' . Html::h($inv['order_id']);
      ?>
        <tr>
          <td><?= Html::h(date('Y-m-d H:i', $inv['created_at'])) ?></td>
          <td><?= Html::h($fmtMoney($inv['amount_cents'], $inv['currency'])) ?></td>
          <td><?= Html::h($inv['status']) ?></td>
          <td><a href="<?= $detailUrl ?>"><?= Html::h(substr($inv['order_id'], 0, 8)) ?></a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
