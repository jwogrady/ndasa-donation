<?php
/**
 * Admin diagnostics — read-only "geek view" plus the Stripe mode toggle
 * (the one write action the page hosts).
 *
 * @var array{
 *   app:        list<array{label:string,status:string,value:string,detail:?string}>,
 *   php:        list<array{label:string,status:string,value:string,detail:?string}>,
 *   database:   list<array{label:string,status:string,value:string,detail:?string}>,
 *   filesystem: list<array{label:string,status:string,value:string,detail:?string}>,
 *   logs:       list<array{label:string,status:string,value:string,detail:?string}>,
 *   env:        list<array{label:string,status:string,value:string,detail:?string}>,
 *   stripe_keys:list<array{label:string,status:string,value:string,detail:?string}>,
 *   stripe_api: list<array{label:string,status:string,value:string,detail:?string}>,
 *   heartbeat:  list<array{label:string,status:string,value:string,detail:?string}>,
 *   log_tail:   list<string>
 * } $diagnostics
 * @var string  $appVersion
 * @var string  $stripeMode   'live' | 'test'
 * @var bool    $liveReady
 * @var bool    $testReady
 * @var string  $csrf
 * @var ?string $flashOk
 * @var ?string $flashErr
 * @var list<array{id:int,actor:string,action:string,detail:?string,created_at:int}> $auditEntries
 */

use NDASA\Support\Html;

$title  = 'Diagnostics';
$active = 'diagnostics';

$renderTile = static function (array $t): string {
    $status = htmlspecialchars((string) $t['status'], ENT_QUOTES, 'UTF-8');
    $label  = htmlspecialchars((string) $t['label'],  ENT_QUOTES, 'UTF-8');
    $value  = htmlspecialchars((string) $t['value'],  ENT_QUOTES, 'UTF-8');
    $detail = $t['detail'] !== null
        ? '<div class="diag-tile__detail">' . htmlspecialchars((string) $t['detail'], ENT_QUOTES, 'UTF-8') . '</div>'
        : '';
    return "<div class=\"diag-tile diag-tile--{$status}\">"
         . "<div class=\"diag-tile__label\">{$label}</div>"
         . "<div class=\"diag-tile__value\">{$value}</div>"
         . "{$detail}"
         . "</div>";
};

$renderSection = static function (string $heading, array $tiles) use ($renderTile): string {
    $id    = 'diag-' . strtolower(str_replace(' ', '-', $heading));
    $body  = '';
    foreach ($tiles as $t) {
        $body .= $renderTile($t);
    }
    return '<section class="diag-section" aria-labelledby="' . $id . '">'
         . '<h2 id="' . $id . '">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h2>'
         . '<div class="diag-grid">' . $body . '</div>'
         . '</section>';
};

$isTest    = $stripeMode === 'test';
$canFlip   = $isTest ? $liveReady : $testReady;
$nextMode  = $isTest ? 'live' : 'test';
$nextLabel = $isTest ? 'LIVE' : 'TEST';

ob_start();
?>
<h1>Diagnostics</h1>

<p class="panel-intro">
  Real-time read-only status. The only write action on this page is the
  Stripe mode toggle below. Configuration lives in <code>.env</code> and
  is edited over SSH.
</p>

<?php if (!empty($flashOk)): ?>
  <div class="notice notice--ok" role="status"><?= Html::h($flashOk) ?></div>
<?php endif; ?>
<?php if (!empty($flashErr)): ?>
  <div class="notice notice--err" role="alert"><?= Html::h($flashErr) ?></div>
<?php endif; ?>

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

<nav class="diag-anchors" aria-label="Jump to section">
  <a href="#diag-app">App</a>
  <a href="#diag-stripe-api">Stripe API</a>
  <a href="#diag-stripe-keys">Stripe keys</a>
  <a href="#diag-webhook-heartbeat">Heartbeat</a>
  <a href="#diag-php">PHP</a>
  <a href="#diag-database">Database</a>
  <a href="#diag-filesystem">Filesystem</a>
  <a href="#diag-logs">Logs</a>
  <a href="#diag-env-vars">Env</a>
</nav>

<?= $renderSection('App',               $diagnostics['app']) ?>
<?= $renderSection('Stripe API',        $diagnostics['stripe_api']) ?>
<?= $renderSection('Stripe keys',       $diagnostics['stripe_keys']) ?>
<?= $renderSection('Webhook heartbeat', $diagnostics['heartbeat']) ?>
<?= $renderSection('PHP',               $diagnostics['php']) ?>
<?= $renderSection('Database',          $diagnostics['database']) ?>
<?= $renderSection('Filesystem',        $diagnostics['filesystem']) ?>
<?= $renderSection('Logs',              $diagnostics['logs']) ?>
<?= $renderSection('Env vars',          $diagnostics['env']) ?>

<?php if ($diagnostics['log_tail'] !== []): ?>
  <section class="diag-section" aria-labelledby="diag-log-tail">
    <h2 id="diag-log-tail">Recent error log</h2>
    <pre class="diag-logtail"><?php foreach ($diagnostics['log_tail'] as $line): ?><?= Html::h($line) ?>
<?php endforeach; ?></pre>
  </section>
<?php endif; ?>

<?php if ($auditEntries !== []): ?>
  <section class="diag-section" aria-labelledby="diag-audit">
    <h2 id="diag-audit">Recent admin actions</h2>
    <table>
      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach ($auditEntries as $entry): ?>
        <tr>
          <td><?= Html::h(date('Y-m-d H:i', $entry['created_at'])) ?></td>
          <td><?= Html::h($entry['actor']) ?></td>
          <td><?= Html::h($entry['action']) ?></td>
          <td><?= Html::h($entry['detail'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>

<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
