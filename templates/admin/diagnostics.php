<?php
/**
 * Admin diagnostics — read-only "geek view".
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
 * @var string $appVersion
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

ob_start();
?>
<h1>Diagnostics</h1>

<p class="panel-intro">
  Real-time read-only status. Nothing on this page can change configuration —
  edit <code>.env</code> over SSH.
</p>

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

<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
