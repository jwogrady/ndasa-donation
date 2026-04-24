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
 *          refunded_at:?int,
 *          dedication:?string,
 *          interval:?string,
 *      }> $recent
 * @var string           $appVersion       Resolved app version string.
 * @var string           $stripeMode       'live' or 'test' (drives the test-mode banner).
 * @var ?int             $lastWebhookAt    Unix ts of most recent webhook ingest (any mode), or null.
 * @var ?int             $lastWebhookLiveAt Unix ts of most recent live-mode webhook, or null.
 * @var ?int             $lastWebhookTestAt Unix ts of most recent test-mode webhook, or null.
 * @var array{subscriptions:int,monthly_cents:int} $recurring Active recurring commitment.
 * @var list<array{email:string,contact_name:?string,donations:int,total_cents:int,last_at:int}> $repeatDonors
 * @var list<array{date:string,count:int,total_cents:int}> $daily30 Last 30 calendar days, oldest-first.
 * @var array{donations:int,refunded:int,rate_pct:float} $refundRate 30-day refund rate.
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

<?php if ($stripeMode === 'test'): ?>
  <div class="notice notice--ok" role="status">
    <strong>TEST mode active.</strong> Dashboard numbers reflect Stripe test transactions only.
    Switch modes on <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin/diagnostics">Diagnostics</a>.
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

<?php
// ──────── Pulse section ────────
// Five inline panels answer five operational questions at a glance:
// plumbing health, recurring commitment, trend, retention, refund spike.

// Webhook heartbeat: red/amber/green reflects the currently-active mode's
// pipe health, because that's the pipe donations are flowing through right
// now. Both mode timestamps still render in the tile body so test chatter
// can't mask live silence (the specific failure we had in prod).
$hbAgeLabel = static function (?int $ts): array {
    if ($ts === null) {
        return ['gone', 'never'];
    }
    $age = time() - $ts;
    if ($age < 3600)  { return ['ok',   'within the last hour']; }
    if ($age < 86400) { return ['warn', 'within the last day']; }
    $d = intdiv($age, 86400);
    $h = intdiv($age % 86400, 3600);
    return ['bad', ($d > 0 ? $d . 'd ' : '') . $h . 'h ago'];
};

[$hbLiveStatus, $hbLiveLabel] = $hbAgeLabel($lastWebhookLiveAt);
[$hbTestStatus, $hbTestLabel] = $hbAgeLabel($lastWebhookTestAt);
[$hbStatus,     $hbLabel]     = $stripeMode === 'test'
    ? [$hbTestStatus, $hbTestLabel]
    : [$hbLiveStatus, $hbLiveLabel];

// Build a simple unit-normalized polyline for the 30-day sparkline.
$sparkPath = '';
$sparkMax  = 0;
$sparkSum  = 0;
foreach ($daily30 as $d) {
    if ($d['total_cents'] > $sparkMax) { $sparkMax = $d['total_cents']; }
    $sparkSum += $d['total_cents'];
}
if ($daily30 !== []) {
    // SVG coordinate space: 240x40, 2px top/bottom padding.
    $n = count($daily30);
    $w = 240;
    $h = 40;
    $pad = 2;
    $step = ($n > 1) ? ($w / ($n - 1)) : 0;
    $points = [];
    foreach ($daily30 as $i => $d) {
        $x = round($i * $step, 2);
        $y = $sparkMax > 0
            ? round($h - $pad - (($d['total_cents'] / $sparkMax) * ($h - (2 * $pad))), 2)
            : ($h - $pad);
        $points[] = "{$x},{$y}";
    }
    $sparkPath = implode(' ', $points);
}
?>

<div class="pulse">

  <div class="pulse__tile pulse__tile--hb pulse__tile--<?= Html::h($hbStatus) ?>">
    <div class="pulse__label">Last Webhook (<?= $stripeMode === 'test' ? 'test' : 'live' ?>)</div>
    <div class="pulse__value"><?= Html::h($hbLabel) ?></div>
    <div class="pulse__sub">
      <span class="hb-mode hb-mode--<?= Html::h($hbLiveStatus) ?>">
        live: <?= $lastWebhookLiveAt !== null ? Html::h(date('Y-m-d H:i', $lastWebhookLiveAt)) : '—' ?>
      </span>
      <span class="hb-mode hb-mode--<?= Html::h($hbTestStatus) ?>">
        test: <?= $lastWebhookTestAt !== null ? Html::h(date('Y-m-d H:i', $lastWebhookTestAt)) : '—' ?>
      </span>
    </div>
  </div>

  <div class="pulse__tile">
    <div class="pulse__label">Active Recurring</div>
    <div class="pulse__value"><?= Html::h($fmtTotal($recurring['monthly_cents'])) ?>
      <span class="pulse__unit">/mo</span></div>
    <div class="pulse__sub">
      <?= Html::h((string) $recurring['subscriptions']) ?>
      <?= $recurring['subscriptions'] === 1 ? 'subscription' : 'subscriptions' ?>
      (yearly plans normalized)
    </div>
  </div>

  <div class="pulse__tile pulse__tile--wide">
    <div class="pulse__label">Last 30 Days</div>
    <div class="pulse__value"><?= Html::h($fmtTotal($sparkSum)) ?></div>
    <?php if ($sparkPath !== ''): ?>
      <svg class="pulse__spark" viewBox="0 0 240 40" preserveAspectRatio="none"
           role="img" aria-label="30-day donation total trend">
        <polyline fill="none" stroke="currentColor" stroke-width="1.5"
                  points="<?= Html::h($sparkPath) ?>"/>
      </svg>
    <?php endif; ?>
    <div class="pulse__sub">Daily totals (<?= Html::h((string) count($daily30)) ?> days)</div>
  </div>

  <?php
    // Refund rate traffic light: 0% green, <2% ok, <5% warn, >=5% bad.
    $rate = $refundRate['rate_pct'];
    $rrStatus = $rate >= 5 ? 'bad' : ($rate >= 2 ? 'warn' : 'ok');
  ?>
  <div class="pulse__tile pulse__tile--<?= Html::h($rrStatus) ?>">
    <div class="pulse__label">Refund Rate (30d)</div>
    <div class="pulse__value"><?= Html::h(number_format($rate, 1)) ?>%</div>
    <div class="pulse__sub">
      <?= Html::h((string) $refundRate['refunded']) ?>
      refunded of
      <?= Html::h((string) $refundRate['donations']) ?>
      donations
    </div>
  </div>

</div>

<?php if ($repeatDonors !== []): ?>
<h2>Repeat Donors</h2>
<div class="panel">
  <table>
    <thead>
      <tr>
        <th>Donor</th>
        <th>Donations</th>
        <th>Total</th>
        <th>Last Gift</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($repeatDonors as $rd): ?>
      <tr>
        <td>
          <?= Html::h($rd['contact_name'] ?? $rd['email']) ?>
          <?php if ($rd['contact_name'] !== null && $rd['contact_name'] !== ''): ?>
            <span class="muted">&lt;<?= Html::h($rd['email']) ?>&gt;</span>
          <?php endif; ?>
        </td>
        <td><?= Html::h((string) $rd['donations']) ?></td>
        <td><?= Html::h($fmtTotal($rd['total_cents'])) ?></td>
        <td><?= Html::h(date('Y-m-d', $rd['last_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<h2>Recent Donations</h2>
<p class="muted mode-filter-note">
  Showing <strong><?= $stripeMode === 'test' ? 'TEST' : 'LIVE' ?></strong> donations only.
  Metrics, the table below, and the CSV export all reflect the current Stripe mode.
</p>
<div class="panel">
  <form method="get" action="<?= Html::h(NDASA_BASE_PATH) ?>/admin/export" class="export-form">
    <label>
      <span class="label-text">From</span>
      <input type="date" name="from" value="">
    </label>
    <label>
      <span class="label-text">To</span>
      <input type="date" name="to" value="">
    </label>
    <button type="submit">Export CSV</button>
    <span class="help muted">Leave dates blank to export all donations.</span>
  </form>
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
      <?php if ($recent === []): ?>
        <tr class="empty"><td colspan="5">No donations to display yet.</td></tr>
      <?php else: ?>
        <?php foreach ($recent as $d): ?>
          <?php
            $name = $d['contact_name'] !== null && $d['contact_name'] !== ''
                ? $d['contact_name']
                : $d['email'];
            $date = date('Y-m-d H:i', $d['created_at']);
            $detailUrl = Html::h(NDASA_BASE_PATH) . '/admin/donations/' . Html::h($d['order_id']);
            $freq = match ($d['interval']) {
                'month' => 'Monthly',
                'year'  => 'Yearly',
                default => 'One-time',
            };
          ?>
          <tr>
            <td><a href="<?= $detailUrl ?>"><?= Html::h($date) ?></a></td>
            <td><a href="<?= $detailUrl ?>"><?= Html::h($name) ?></a></td>
            <td><?= Html::h($fmtMoney($d['amount_cents'], $d['currency'])) ?></td>
            <td><?= Html::h($freq) ?></td>
            <td><?= Html::h($d['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<p class="muted">
  Admin activity log, system health, and configuration live on
  <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin/diagnostics">Diagnostics</a>.
</p>

<p class="muted fineprint">
  Page views are counted per GET request to <code>/</code>, throttled to one
  entry per 30 seconds per session. Donation counts and totals reflect
  webhook-confirmed successful payments only (rows with <code>status = 'paid'</code>);
  refunded and failed attempts are excluded so the dashboard shows actual
  revenue. The Stripe dashboard remains the authoritative record for
  financial reconciliation.
</p>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
