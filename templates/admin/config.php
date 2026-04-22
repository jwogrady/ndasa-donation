<?php
/**
 * Admin config editor.
 *
 * @var array<string,string> $values    Current values to prefill.
 * @var ?string              $flashOk   Success message, if any.
 * @var ?string              $flashErr  Error message, if any.
 * @var array<string>        $fields    Ordered list of editable field keys.
 * @var array<string,string> $descriptions  key -> human-readable help text.
 */

use NDASA\Support\Html;

$title  = 'Config';
$active = 'config';

ob_start();
?>
<h1>Config</h1>

<?php if (!empty($flashOk)): ?>
  <div class="notice notice--ok" role="status"><?= Html::h($flashOk) ?></div>
<?php endif; ?>
<?php if (!empty($flashErr)): ?>
  <div class="notice notice--err" role="alert"><?= Html::h($flashErr) ?></div>
<?php endif; ?>

<div class="panel">
  <p class="muted" style="margin-top: 0;">
    Values are read from and saved to the application's <code>.env</code> file.
    A PHP-FPM reload may be required for changes to take full effect; until
    then the running process will continue to use the previous values.
  </p>

  <form method="post" action="/admin/config" autocomplete="off">
    <?php foreach ($fields as $key): ?>
      <?php
        $isSecret = str_contains($key, 'SECRET') || str_contains($key, 'PASS');
        $type = $isSecret ? 'password' : ($key === 'APP_URL' ? 'url' : ($key === 'MAIL_BCC_INTERNAL' ? 'email' : 'text'));
      ?>
      <label>
        <span class="label-text"><?= Html::h($key) ?></span>
        <input
          type="<?= Html::h($type) ?>"
          name="<?= Html::h($key) ?>"
          value="<?= Html::h($values[$key] ?? '') ?>"
          <?= $isSecret ? 'autocomplete="new-password"' : '' ?>
        >
        <?php if (!empty($descriptions[$key])): ?>
          <span class="help"><?= Html::h($descriptions[$key]) ?></span>
        <?php endif; ?>
      </label>
    <?php endforeach; ?>

    <button type="submit">Save changes</button>
  </form>
</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/layout.php';
