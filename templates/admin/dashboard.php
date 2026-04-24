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
 * @var list<string>     $missingRequired  Required env vars currently empty.
 * @var list<string>     $missingIndexes   Expected indexes not yet created.
 * @var array<string,list<array{label:string,ok:bool,detail:?string}>> $health
 *          Grouped health-check rows keyed by section name.
 * @var string           $appVersion       Resolved app version string.
 * @var string           $stripeMode       'live' or 'test'.
 * @var bool             $testReady        True when STRIPE_TEST_* vars are set.
 * @var bool             $liveReady        True when live STRIPE_* vars are set.
 * @var string           $csrf             CSRF token for the mode-toggle form.
 * @var ?string          $flashOk          Success flash from the mode toggle.
 * @var ?string          $flashErr         Error flash from the mode toggle.
 * @var list<array{id:int,actor:string,action:string,detail:?string,created_at:int}> $auditEntries
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

<?php if (!empty($missingRequired)): ?>
  <div class="notice notice--err" role="alert">
    <strong>Configuration incomplete &mdash; donations may fail.</strong>
    Required values not set: <?= Html::h(implode(', ', $missingRequired)) ?>.
    Visit <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin/config">Config</a> to fix.
  </div>
<?php endif; ?>

<?php if (!empty($missingIndexes)): ?>
  <div class="notice notice--err" role="alert">
    Database optimization recommended (missing indexes).
    Visit any page to trigger the auto-migration, or restart PHP-FPM so the
    migration block runs against the live database.
  </div>
<?php endif; ?>

<?php if (!empty($flashOk)): ?>
  <div class="notice notice--ok" role="status"><?= Html::h($flashOk) ?></div>
<?php endif; ?>
<?php if (!empty($flashErr)): ?>
  <div class="notice notice--err" role="alert"><?= Html::h($flashErr) ?></div>
<?php endif; ?>

<?php
  $isTest  = $stripeMode === 'test';
  $canFlip = $isTest ? $liveReady : $testReady;
  $nextMode = $isTest ? 'live' : 'test';
  $nextLabel = $isTest ? 'LIVE' : 'TEST';
?>
<section class="mode-panel mode-panel--<?= $isTest ? 'test' : 'live' ?>" aria-labelledby="mode-heading">
  <div class="mode-panel__left">
    <div class="mode-panel__pulse" aria-hidden="true"></div>
    <div>
      <div class="mode-panel__eyebrow">Payment Mode</div>
      <h2 id="mode-heading" class="mode-panel__title">
        <?= $isTest ? 'TEST MODE' : 'LIVE MODE' ?>
      </h2>
      <p class="mode-panel__sub">
        <?php if ($isTest): ?>
          Using Stripe test credentials. Payments are simulated — no money will be moved.
        <?php else: ?>
          Using Stripe live credentials. Real cards, real charges.
        <?php endif; ?>
      </p>
    </div>
  </div>
  <form method="post" action="<?= Html::h(NDASA_BASE_PATH) ?>/admin/stripe-mode" class="mode-panel__form">
    <input type="hidden" name="<?= \NDASA\Http\Csrf::FIELD ?>" value="<?= Html::h($csrf) ?>">
    <input type="hidden" name="mode" value="<?= Html::h($nextMode) ?>">
    <button type="submit" class="mode-panel__btn" <?= $canFlip ? '' : 'disabled' ?>
        onclick="return confirm('Switch Stripe to <?= $nextLabel ?> mode? Future checkouts will use <?= $nextLabel ?> credentials immediately.')">
      Switch to <?= $nextLabel ?>
    </button>
    <?php if (!$canFlip): ?>
      <p class="mode-panel__hint">
        Missing <?= $isTest ? 'live' : 'test' ?> credentials in .env
        (<code>STRIPE_<?= $isTest ? 'LIVE' : 'TEST' ?>_SECRET_KEY</code>,
        <code>STRIPE_<?= $isTest ? 'LIVE' : 'TEST' ?>_WEBHOOK_SECRET</code>).
      </p>
    <?php endif; ?>
  </form>
</section>

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
  Showing <strong><?= $isTest ? 'TEST' : 'LIVE' ?></strong> donations only.
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

<h2>Admin Activity</h2>
<div class="panel">
  <table>
    <thead>
      <tr>
        <th>When</th>
        <th>Actor</th>
        <th>Action</th>
        <th>Detail</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($auditEntries === []): ?>
        <tr class="empty"><td colspan="4">No admin activity recorded yet.</td></tr>
      <?php else: ?>
        <?php foreach ($auditEntries as $a): ?>
          <tr>
            <td><?= Html::h(date('Y-m-d H:i', $a['created_at'])) ?></td>
            <td><?= Html::h($a['actor']) ?></td>
            <td><?= Html::h($a['action']) ?></td>
            <td class="muted"><?= $a['detail'] === null ? '' : Html::h($a['detail']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<h2>System Health</h2>
<?php foreach ($health as $section => $rows): ?>
  <div class="panel">
    <h3 class="panel-subheading">
      <?= Html::h($section) ?>
    </h3>
    <table>
      <thead>
        <tr>
          <th class="col-check">Check</th>
          <th class="col-status">Status</th>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $h): ?>
          <tr>
            <td><?= Html::h($h['label']) ?></td>
            <td>
              <?php if ($h['ok']): ?>
                <span class="health-status-ok">OK</span>
              <?php else: ?>
                <span class="health-status-fail">FAIL</span>
              <?php endif; ?>
            </td>
            <td class="<?= $h['ok'] ? 'muted' : 'health-detail-fail' ?>">
              <?= $h['detail'] === null ? '' : Html::h($h['detail']) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

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
