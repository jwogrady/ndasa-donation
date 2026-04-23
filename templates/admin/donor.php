<?php
/**
 * Donor detail view.
 *
 * @var array{email:string,contact_name:?string,total_cents:int,donations:list<array<string,mixed>>,subscriptions:list<string>,last_optin:?bool} $donor
 * @var array<string,string> $receiptUrls  PI id -> hosted Stripe receipt URL.
 * @var string $stripeMode
 * @var string $appVersion
 */

use NDASA\Support\Html;

$title  = 'Donor ' . ($donor['contact_name'] ?? $donor['email']);
$active = 'donors';

$fmtMoney = static fn (int $c, string $cur = 'USD'): string =>
    '$' . number_format($c / 100, 2) . ' ' . strtoupper($cur);

$paidCount = 0;
$firstAt = PHP_INT_MAX;
$lastAt  = 0;
$latestCurrency = 'USD';
foreach ($donor['donations'] as $d) {
    if ($d['status'] === 'paid') {
        $paidCount++;
    }
    $firstAt = min($firstAt, $d['created_at']);
    $lastAt  = max($lastAt,  $d['created_at']);
    $latestCurrency = $d['currency'];
}
if ($firstAt === PHP_INT_MAX) { $firstAt = 0; }

ob_start();
?>
<h1>Donor</h1>
<p class="muted">
  <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin/donors">&larr; All donors</a>
</p>

<div class="donor-header">
  <div>
    <h2 style="margin:0">
      <?= Html::h($donor['contact_name'] ?? $donor['email']) ?>
    </h2>
    <div class="donor-header__email">
      <a href="mailto:<?= Html::h($donor['email']) ?>"><?= Html::h($donor['email']) ?></a>
    </div>
    <div class="donor-header__optin">
      <?php if ($donor['last_optin'] === true): ?>
        <span class="donor-header__optin--yes">Opted in to updates</span>
      <?php elseif ($donor['last_optin'] === false): ?>
        <span class="donor-header__optin--no">Opted out</span>
      <?php else: ?>
        <span class="muted">Opt-in unknown</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="donor-header__stat">
    <div class="donor-header__stat-label">Lifetime giving</div>
    <div class="donor-header__stat-value"><?= Html::h($fmtMoney($donor['total_cents'], $latestCurrency)) ?></div>
    <div class="muted">
      <?= Html::h((string) $paidCount) ?>
      <?= $paidCount === 1 ? 'gift' : 'gifts' ?>
      <?php if ($firstAt > 0): ?>
        &middot; first <?= Html::h(date('Y-m-d', $firstAt)) ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($donor['subscriptions'] !== []): ?>
<h2>Subscriptions</h2>
<div class="panel">
  <ul>
    <?php foreach ($donor['subscriptions'] as $sid): ?>
      <li>
        <a href="<?= Html::h(NDASA_BASE_PATH) ?>/admin/subscriptions/<?= Html::h($sid) ?>">
          <?= Html::h($sid) ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<h2>Donations</h2>
<div class="panel">
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Amount</th>
        <th>Frequency</th>
        <th>Status</th>
        <th>Dedication</th>
        <th>Links</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($donor['donations'] as $d):
        $detailUrl = Html::h(NDASA_BASE_PATH) . '/admin/donations/' . Html::h($d['order_id']);
        $freq = match ($d['interval']) {
            'month' => 'Monthly',
            'year'  => 'Yearly',
            default => 'One-time',
        };
        $receiptUrl = ($d['payment_intent_id'] !== null && isset($receiptUrls[$d['payment_intent_id']]))
            ? $receiptUrls[$d['payment_intent_id']]
            : null;
      ?>
        <tr>
          <td><a href="<?= $detailUrl ?>"><?= Html::h(date('Y-m-d H:i', $d['created_at'])) ?></a></td>
          <td><?= Html::h($fmtMoney($d['amount_cents'], $d['currency'])) ?></td>
          <td><?= Html::h($freq) ?></td>
          <td><?= Html::h($d['status']) ?></td>
          <td><?= $d['dedication'] !== null ? Html::h($d['dedication']) : '' ?></td>
          <td>
            <a href="<?= $detailUrl ?>">Detail</a>
            <?php if ($receiptUrl !== null): ?>
              &middot;
              <a href="<?= Html::h($receiptUrl) ?>" target="_blank" rel="noopener noreferrer">
                Stripe receipt &rarr;
              </a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
