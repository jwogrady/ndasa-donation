<?php
/**
 * Subscriptions index.
 *
 * @var list<array{stripe_subscription_id:string,stripe_customer_id:?string,contact_name:?string,email:string,interval:?string,amount_cents:int,invoices:int,total_cents:int,last_at:int,status:string}> $rows
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var string $stripeMode
 */

use NDASA\Support\Html;

$title  = 'Subscriptions';
$active = 'subscriptions';

$fmtMoney = static fn (int $c): string => '$' . number_format($c / 100, 2);
$basePath = Html::h(NDASA_BASE_PATH) . '/admin/subscriptions';

ob_start();
?>
<h1>Subscriptions</h1>
<p class="muted mode-filter-note">
  Showing <strong><?= $stripeMode === 'test' ? 'TEST' : 'LIVE' ?></strong> subscriptions only.
  Status shown here reflects the most recent invoice in our DB; click through for live Stripe status.
</p>

<?php require __DIR__ . '/_pager.php'; ?>

<div class="panel">
  <table>
    <thead>
      <tr>
        <th>Donor</th>
        <th>Amount</th>
        <th>Frequency</th>
        <th>Invoices</th>
        <th>Lifetime</th>
        <th>Last Charge</th>
        <th>Latest Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows === []): ?>
        <tr class="empty"><td colspan="7">No subscriptions yet.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $s):
          $detailUrl = Html::h(NDASA_BASE_PATH) . '/admin/subscriptions/' . Html::h($s['stripe_subscription_id']);
          $donorName = $s['contact_name'] !== null && $s['contact_name'] !== ''
              ? $s['contact_name']
              : $s['email'];
          $freq = match ($s['interval']) {
              'month' => 'Monthly',
              'year'  => 'Yearly',
              default => '—',
          };
        ?>
          <tr>
            <td><a href="<?= $detailUrl ?>"><?= Html::h($donorName) ?></a></td>
            <td><?= Html::h($fmtMoney($s['amount_cents'])) ?></td>
            <td><?= Html::h($freq) ?></td>
            <td><?= Html::h((string) $s['invoices']) ?></td>
            <td><?= Html::h($fmtMoney($s['total_cents'])) ?></td>
            <td><?= Html::h(date('Y-m-d', $s['last_at'])) ?></td>
            <td><?= Html::h($s['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/_pager.php'; ?>

<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
