<?php
/**
 * Admin dashboard.
 *
 * @var int              $pageViews        Count of /page_views rows.
 * @var int              $donationCount    Count of donations rows.
 * @var int              $donorCount       Distinct donor count (by email).
 * @var int              $totalCents       Gross donations amount in cents.
 * @var float            $conversionPct    (donationCount / pageViews) * 100, rounded to 1dp.
 * @var list<array{
 *          order_id:string,
 *          contact_name:?string,
 *          email:string,
 *          amount_cents:int,
 *          currency:string,
 *          status:string,
 *          created_at:int,
 *          refunded_at:?int
 *      }> $recent
 * @var list<string>     $missingRequired  Required env vars currently empty.
 */

use NDASA\Support\Html;

$title  = 'Dashboard';
$active = 'dashboard';

$fmtMoney = static function (int $cents, string $currency): string {
    $n = number_format($cents / 100, 2);
    return '$' . $n . ' ' . strtoupper($currency);
};

$fmtTotal = static function (int $cents): string {
    return '$' . number_format($cents / 100, 2);
};

ob_start();
?>
<h1>NDASA Dashboard</h1>

<?php if (!empty($missingRequired)): ?>
  <div class="notice notice--err" role="alert">
    Configuration incomplete &mdash; check required values:
    <strong><?= Html::h(implode(', ', $missingRequired)) ?></strong>.
    Visit <a href="/admin/config">Config</a> to fix.
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat">
    <div class="stat__label">Total Donations</div>
    <div class="stat__value"><?= Html::h($fmtTotal($totalCents)) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Total Donors</div>
    <div class="stat__value"><?= Html::h((string) $donorCount) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Page Views</div>
    <div class="stat__value"><?= Html::h((string) $pageViews) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Conversion Rate</div>
    <div class="stat__value"><?= Html::h(number_format($conversionPct, 1)) ?>%</div>
  </div>
</div>

<h2>Recent Donations</h2>
<div class="panel">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Donor</th>
        <th>Amount</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($recent === []): ?>
        <tr class="empty"><td colspan="4">No donations to display yet.</td></tr>
      <?php else: ?>
        <?php foreach ($recent as $d): ?>
          <?php
            $name = $d['contact_name'] !== null && $d['contact_name'] !== ''
                ? $d['contact_name']
                : $d['email'];
            $date = date('Y-m-d H:i', $d['created_at']);
          ?>
          <tr>
            <td><?= Html::h($date) ?></td>
            <td><?= Html::h($name) ?></td>
            <td><?= Html::h($fmtMoney($d['amount_cents'], $d['currency'])) ?></td>
            <td><?= Html::h($d['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<p class="muted" style="margin-top: 24px;">
  Page views are counted per GET request to <code>/</code>. Donations are sourced
  from webhook-verified records. Conversion rate = donations &divide; page views.
  The Stripe dashboard remains the authoritative record for financial reconciliation.
</p>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
