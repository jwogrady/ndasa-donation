<?php
/**
 * Transactions index.
 *
 * @var list<array{order_id:string,contact_name:?string,email:string,amount_cents:int,currency:string,status:string,interval:?string,created_at:int,refunded_at:?int,dedication:?string,stripe_subscription_id:?string}> $rows
 * @var int    $total
 * @var int    $page
 * @var int    $perPage
 * @var string $emailQ
 * @var string $status
 * @var string $fromRaw
 * @var string $toRaw
 * @var string $stripeMode
 */

use NDASA\Support\Html;

$title  = 'Transactions';
$active = 'transactions';

$fmtMoney = static fn (int $c, string $cur): string => '$' . number_format($c / 100, 2) . ' ' . strtoupper($cur);
$basePath = Html::h(NDASA_BASE_PATH) . '/admin/transactions';

ob_start();
?>
<h1>Transactions</h1>
<p class="muted mode-filter-note">
  Showing <strong><?= $stripeMode === 'test' ? 'TEST' : 'LIVE' ?></strong> transactions only.
</p>

<form class="index-filters" method="get" action="<?= $basePath ?>">
  <label>
    Email
    <input type="text" name="email" value="<?= Html::h($emailQ) ?>" placeholder="jane@example.com">
  </label>
  <label>
    Status
    <select name="status">
      <option value=""           <?= $status === ''           ? 'selected' : '' ?>>Any</option>
      <option value="paid"       <?= $status === 'paid'       ? 'selected' : '' ?>>Paid</option>
      <option value="refunded"   <?= $status === 'refunded'   ? 'selected' : '' ?>>Refunded</option>
      <option value="cancelled"  <?= $status === 'cancelled'  ? 'selected' : '' ?>>Cancelled</option>
    </select>
  </label>
  <label>
    From
    <input type="date" name="from" value="<?= Html::h($fromRaw) ?>">
  </label>
  <label>
    To
    <input type="date" name="to" value="<?= Html::h($toRaw) ?>">
  </label>
  <input type="hidden" name="per_page" value="<?= Html::h((string) $perPage) ?>">
  <button type="submit">Apply</button>
  <?php if ($emailQ !== '' || $status !== '' || $fromRaw !== '' || $toRaw !== ''): ?>
    <a class="clear" href="<?= $basePath ?>">Clear filters</a>
  <?php endif; ?>
</form>

<?php require __DIR__ . '/_pager.php'; ?>

<div class="panel">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Donor</th>
        <th>Amount</th>
        <th>Frequency</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows === []): ?>
        <tr class="empty"><td colspan="5">No transactions match these filters.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $d):
          $freq = match ($d['interval']) {
              'month' => 'Monthly',
              'year'  => 'Yearly',
              default => 'One-time',
          };
          $detailUrl = Html::h(NDASA_BASE_PATH) . '/admin/donations/' . Html::h($d['order_id']);
          $donorName = $d['contact_name'] !== null && $d['contact_name'] !== ''
              ? $d['contact_name']
              : $d['email'];
        ?>
          <tr>
            <td><a href="<?= $detailUrl ?>"><?= Html::h(date('Y-m-d H:i', $d['created_at'])) ?></a></td>
            <td><a href="<?= $detailUrl ?>"><?= Html::h($donorName) ?></a></td>
            <td><?= Html::h($fmtMoney($d['amount_cents'], $d['currency'])) ?></td>
            <td><?= Html::h($freq) ?></td>
            <td><?= Html::h($d['status']) ?></td>
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
